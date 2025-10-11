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
        $chatId = $request->query->get('chatId', null);
        $includeReasoning = $request->query->get('reasoning', '0') === '1';
        $webSearch = $request->query->get('webSearch', '0') === '1';

        if (empty($messageText)) {
            return $this->json(['error' => 'Message is required'], Response::HTTP_BAD_REQUEST);
        }
        
        if (!$chatId) {
            return $this->json(['error' => 'Chat ID is required'], Response::HTTP_BAD_REQUEST);
        }

        // StreamedResponse für SSE
        $response = new StreamedResponse();
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');

        $response->setCallback(function () use ($user, $messageText, $trackId, $chatId, $includeReasoning, $webSearch) {
            // Disable output buffering
            while (ob_get_level()) {
                ob_end_clean();
            }
            ob_implicit_flush(1);
            set_time_limit(0);
            ignore_user_abort(false);

            try {
                // Load chat
                $chat = $this->em->getRepository(\App\Entity\Chat::class)->find((int)$chatId);
                if (!$chat || $chat->getUserId() !== $user->getId()) {
                    $this->sendSSE('error', ['error' => 'Chat not found or access denied']);
                    return;
                }
                
                // Create incoming message
                $incomingMessage = new Message();
                $incomingMessage->setUserId($user->getId());
                $incomingMessage->setChat($chat);
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

                // Process with REAL streaming (TEXT only, NO JSON!)
                $responseText = '';
                $chunkCount = 0;
                
                $processingOptions = [
                    'reasoning' => $includeReasoning,
                    'webSearch' => $webSearch,
                ];
                
                $result = $this->messageProcessor->processStream(
                    $incomingMessage,
                    // Stream callback - AI streams TEXT directly
                    function($chunk) use (&$responseText, &$chunkCount) {
                        if (connection_aborted()) {
                            error_log('🔴 StreamController: Connection aborted');
                            return;
                        }
                        
                        $responseText .= $chunk;
                        
                        // Stream immediately to frontend
                        if (!empty($chunk)) {
                            $this->sendSSE('data', ['chunk' => $chunk]);
                            
                            if ($chunkCount === 0) {
                                error_log('🔵 StreamController: Started streaming');
                            }
                            $chunkCount++;
                        }
                    },
                    // Status callback
                    function($statusUpdate) {
                        if ($statusUpdate['status'] === 'complete') {
                            return;
                        }
                        
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
                
                error_log('🔵 StreamController: Streaming complete, ' . $chunkCount . ' chunks');
                $this->logger->info('StreamController: Streaming complete', [
                    'chunks' => $chunkCount,
                    'length' => strlen($responseText),
                ]);

                // Get file/links from handler metadata (Handler sets these, not AI!)
                $hasFile = 0;
                $filePath = '';
                $fileType = '';
                
                if (isset($response['metadata']['file'])) {
                    $hasFile = 1;
                    $filePath = $response['metadata']['file']['path'];
                    $fileType = $response['metadata']['file']['type'];
                    
                    $this->sendSSE('file', [
                        'type' => $fileType,
                        'url' => $filePath,
                    ]);
                    
                    $this->logger->info('StreamController: Handler provided file', [
                        'path' => $filePath,
                        'type' => $fileType
                    ]);
                }
                
                if (isset($response['metadata']['links'])) {
                    $this->sendSSE('links', [
                        'links' => $response['metadata']['links']
                    ]);
                    $this->logger->info('StreamController: Handler provided links');
                }

                // Create outgoing message
                $outgoingMessage = new Message();
                $outgoingMessage->setUserId($user->getId());
                $outgoingMessage->setChat($chat);
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
                $outgoingMessage->setText($responseText); // Pure TEXT, not JSON
                $outgoingMessage->setDirection('OUT');
                $outgoingMessage->setStatus('complete');

                $this->em->persist($outgoingMessage);
                
                // Update incoming message
                $incomingMessage->setTopic($classification['topic']);
                $incomingMessage->setLanguage($classification['language']);
                $incomingMessage->setStatus('complete');
                
                $chat->updateTimestamp();
                
                $this->em->flush();

                // Get Again data
                $againData = $this->getAgainData($classification['topic'], null);

                // Send complete event
                $this->sendSSE('complete', [
                    'messageId' => $outgoingMessage->getId(),
                    'trackId' => $trackId,
                    'provider' => $response['metadata']['provider'] ?? 'test',
                    'model' => $response['metadata']['model'] ?? 'unknown',
                    'topic' => $classification['topic'],
                    'language' => $classification['language'],
                    'again' => $againData,
                ]);
                
                usleep(100000);

                $this->logger->info('Streamed message processed', [
                    'user_id' => $user->getId(),
                    'message_id' => $outgoingMessage->getId(),
                    'topic' => $classification['topic'],
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

    private function sendSSE(string $status, array $data): void
    {
        if (connection_aborted()) {
            error_log('🔴 StreamController: Connection aborted');
            return;
        }

        $event = [
            'status' => $status,
            ...$data,
        ];

        echo "data: " . json_encode($event) . "\n\n";
        
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    private function getAgainData(string $topic, ?int $currentModelId): array
    {
        $tag = $this->againService->resolveTagFromTopic($topic);
        $eligibleModels = $this->againService->getEligibleModels($tag);
        $predictedNext = $this->againService->getPredictedNext($eligibleModels, $currentModelId);

        return [
            'eligible' => $eligibleModels,
            'predictedNext' => $predictedNext,
            'tag' => $tag,
        ];
    }
}
