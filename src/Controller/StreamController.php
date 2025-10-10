<?php

namespace App\Controller;

use App\Entity\Message;
use App\Entity\User;
use App\AI\Service\AiFacade;
use App\Service\Message\MessageProcessor;
use App\Service\AgainService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/messages', name: 'api_messages_')]
class StreamController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private AiFacade $aiFacade,
        private MessageProcessor $messageProcessor,
        private AgainService $againService,
        private LoggerInterface $logger
    ) {}

    #[Route('/stream', name: 'stream', methods: ['GET'])]
    public function streamMessage(
        Request $request,
        #[CurrentUser] ?User $user
    ): Response {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $messageText = $request->query->get('message', '');
        $trackId = $request->query->get('trackId', time());
        $includeReasoning = $request->query->get('reasoning', '0') === '1';

        if (empty($messageText)) {
            return $this->json(['error' => 'Message is required'], Response::HTTP_BAD_REQUEST);
        }

        // Create StreamedResponse for SSE
        $response = new StreamedResponse();
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no'); // Disable nginx buffering
        $response->headers->set('Connection', 'keep-alive');

        $response->setCallback(function () use ($user, $messageText, $trackId, $includeReasoning) {
            // Disable output buffering completely
            while (ob_get_level()) {
                ob_end_clean();
            }
            ob_implicit_flush(1);
            set_time_limit(0);
            ignore_user_abort(false); // Stop if connection is lost

            try {
                // Create incoming message
                $incomingMessage = new Message();
                $incomingMessage->setUserId($user->getId());
                $incomingMessage->setTrackingId($trackId);
                $incomingMessage->setProviderIndex('WEB');
                $incomingMessage->setUnixTimestamp(time());
                $incomingMessage->setDateTime(date('YmdHis'));
                $incomingMessage->setMessageType('WEB');
                $incomingMessage->setFile(0);
                $incomingMessage->setTopic('CHAT');
                $incomingMessage->setLanguage('en');
                $incomingMessage->setText($messageText);
                $incomingMessage->setDirection('IN');
                $incomingMessage->setStatus('processing');

                $this->em->persist($incomingMessage);
                $this->em->flush();

                // Process with REAL streaming support
                $responseText = '';
                $chunkCount = 0;
                
                // Processing options to pass through the chain
                $processingOptions = [
                    'reasoning' => $includeReasoning, // Let model/provider decide if it supports reasoning
                ];
                
                $result = $this->messageProcessor->processStream(
                    $incomingMessage,
                    // Stream callback - called for each AI chunk
                    function($chunk) use (&$responseText, &$chunkCount) {
                        if (connection_aborted()) {
                            error_log('🔴 StreamController: Connection aborted during streaming');
                            return;
                        }
                        
                        // Accumulate full response for DB storage
                        $responseText .= $chunk;
                        
                        // Send chunk immediately to frontend
                        if (!empty($chunk)) {
                            $this->sendSSE('data', ['chunk' => $chunk]);
                            
                            if ($chunkCount === 0) {
                                error_log('🔵 StreamController: Started REAL-TIME streaming');
                            }
                            $chunkCount++;
                        }
                    },
                    // Status callback - called for processing updates
                    function($statusUpdate) {
                        // Skip 'complete' event - we'll send it after streaming
                        if ($statusUpdate['status'] === 'complete') {
                            return;
                        }
                        
                        // Send status update as SSE
                        $this->sendSSE($statusUpdate['status'], [
                            'message' => $statusUpdate['message'],
                            'metadata' => $statusUpdate['metadata'] ?? [],
                            'timestamp' => $statusUpdate['timestamp']
                        ]);
                    },
                    $processingOptions
                );

                if (!$result['success']) {
                    throw new \RuntimeException($result['error']);
                }

                $classification = $result['classification'];
                $response = $result['response'];
                
                error_log('🔵 StreamController: REAL-TIME streaming complete, ' . $chunkCount . ' chunks sent');
                $this->logger->info('StreamController: Real-time streaming complete', [
                    'chunks' => $chunkCount,
                    'total_length' => strlen($responseText),
                    'reasoning_included' => $includeReasoning
                ]);

                // Parse for media markers
                $hasFile = 0;
                $filePath = '';
                $fileType = '';
                $fullResponse = $responseText;

                if (preg_match('/\[IMAGE:(.+?)\]/', $fullResponse, $matches)) {
                    $hasFile = 1;
                    $filePath = $matches[1];
                    $fileType = 'png';
                    $fullResponse = trim(preg_replace('/\[IMAGE:.+?\]/', '', $fullResponse));
                    
                    $this->sendSSE('file', [
                        'type' => 'image',
                        'url' => $filePath,
                    ]);
                } elseif (preg_match('/\[VIDEO:(.+?)\]/', $fullResponse, $matches)) {
                    $hasFile = 1;
                    $filePath = $matches[1];
                    $fileType = 'mp4';
                    $fullResponse = trim(preg_replace('/\[VIDEO:.+?\]/', '', $fullResponse));
                    
                    $this->sendSSE('file', [
                        'type' => 'video',
                        'url' => $filePath,
                    ]);
                }

                // Create outgoing message
                $outgoingMessage = new Message();
                $outgoingMessage->setUserId($user->getId());
                $outgoingMessage->setTrackingId($trackId);
                $outgoingMessage->setProviderIndex($response['metadata']['provider'] ?? 'test');
                $outgoingMessage->setUnixTimestamp(time());
                $outgoingMessage->setDateTime(date('YmdHis'));
                $outgoingMessage->setMessageType('WEB');
                $outgoingMessage->setFile($hasFile);
                $outgoingMessage->setFilePath($filePath);
                $outgoingMessage->setFileType($fileType);
                $outgoingMessage->setTopic($classification['topic']);
                $outgoingMessage->setLanguage($classification['language']);
                $outgoingMessage->setText($fullResponse);
                $outgoingMessage->setDirection('OUT');
                $outgoingMessage->setStatus('complete');

                $this->em->persist($outgoingMessage);
                
                // Update incoming message
                $incomingMessage->setTopic($classification['topic']);
                $incomingMessage->setLanguage($classification['language']);
                $incomingMessage->setStatus('complete');
                
                $this->em->flush();

                // Get Again data for frontend
                $againData = $this->getAgainData($classification['topic'], null);

                // Send complete event with full metadata
                $this->sendSSE('complete', [
                    'messageId' => $outgoingMessage->getId(),
                    'trackId' => $trackId,
                    'provider' => $response['metadata']['provider'] ?? 'test',
                    'model' => $response['metadata']['model'] ?? 'unknown',
                    'topic' => $classification['topic'],
                    'language' => $classification['language'],
                    'again' => $againData,
                ]);
                
                // Give frontend 100ms to process complete event before connection closes
                usleep(100000);

                $this->logger->info('Streamed message processed', [
                    'user_id' => $user->getId(),
                    'message_id' => $outgoingMessage->getId(),
                    'topic' => $classification['topic'],
                    'model' => $response['metadata']['model'] ?? 'unknown',
                ]);

            } catch (\Exception $e) {
                $this->logger->error('Streaming failed', [
                    'user_id' => $user->getId(),
                    'error' => $e->getMessage(),
                ]);

                $this->sendSSE('error', [
                    'error' => 'Failed to process message: ' . $e->getMessage(),
                ]);
            }
        });

        return $response;
    }

    /**
     * Send Server-Sent Event
     */
    private function sendSSE(string $status, array $data): void
    {
        // Check if connection is still alive
        if (connection_aborted()) {
            error_log('🔴 StreamController: Connection aborted');
            return;
        }

        $event = [
            'status' => $status,
            ...$data,
        ];

        echo "data: " . json_encode($event) . "\n\n";
        
        // Ensure data is flushed
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    /**
     * Get Again data (eligible models and predicted next)
     */
    private function getAgainData(string $topic, ?int $currentModelId): array
    {
        // Resolve tag from topic
        $tag = $this->againService->resolveTagFromTopic($topic);
        
        // Get eligible models
        $eligibleModels = $this->againService->getEligibleModels($tag);
        
        // Get predicted next
        $predictedNext = $this->againService->getPredictedNext($eligibleModels, $currentModelId);

        return [
            'eligible' => $eligibleModels,
            'predictedNext' => $predictedNext,
            'tag' => $tag,
        ];
    }
}

