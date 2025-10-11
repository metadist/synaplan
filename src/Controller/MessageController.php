<?php

namespace App\Controller;

use App\Entity\Message;
use App\Entity\User;
use App\AI\Service\AiFacade;
use App\Service\AgainService;
use App\Service\Message\AgainHandler;
use App\Service\PromptService;
use App\Service\ModelConfigService;
use App\Service\MessageEnqueueService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/messages', name: 'api_messages_')]
class MessageController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private AiFacade $aiFacade,
        private AgainService $againService,
        private AgainHandler $againHandler,
        private PromptService $promptService,
        private ModelConfigService $modelConfigService,
        private MessageEnqueueService $enqueueService,
        private LoggerInterface $logger
    ) {}

    #[Route('/send', name: 'send', methods: ['POST'])]
    public function sendMessage(
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $messageText = $data['message'] ?? '';
        $trackId = $data['trackId'] ?? time();

        if (empty($messageText)) {
            return $this->json(['error' => 'Message is required'], Response::HTTP_BAD_REQUEST);
        }

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

            // Use AI Facade to get response
            $aiResponse = $this->aiFacade->chat(
                [
                    ['role' => 'user', 'content' => $messageText]
                ],
                $user->getId()
            );

            // Parse response for special content markers
            $hasFile = 0;
            $filePath = '';
            $fileType = '';
            $responseText = $aiResponse['content'] ?? '';

            // Check for [IMAGE:url] marker
            if (preg_match('/\[IMAGE:(.+?)\]/', $responseText, $matches)) {
                $hasFile = 1;
                $filePath = $matches[1];
                $fileType = 'png';
                $responseText = trim(preg_replace('/\[IMAGE:.+?\]/', '', $responseText));
            }
            // Check for [VIDEO:url] marker
            elseif (preg_match('/\[VIDEO:(.+?)\]/', $responseText, $matches)) {
                $hasFile = 1;
                $filePath = $matches[1];
                $fileType = 'mp4';
                $responseText = trim(preg_replace('/\[VIDEO:.+?\]/', '', $responseText));
            }

            // Create outgoing message
            $outgoingMessage = new Message();
            $outgoingMessage->setUserId($user->getId());
            $outgoingMessage->setTrackingId($trackId);
            $outgoingMessage->setProviderIndex($aiResponse['provider'] ?? 'test');
            $outgoingMessage->setUnixTimestamp(time());
            $outgoingMessage->setDateTime(date('YmdHis'));
            $outgoingMessage->setMessageType('WEB');
            $outgoingMessage->setFile($hasFile);
            $outgoingMessage->setFilePath($filePath);
            $outgoingMessage->setFileType($fileType);
            $outgoingMessage->setTopic('CHAT');
            $outgoingMessage->setLanguage('en');
            $outgoingMessage->setText($responseText);
            $outgoingMessage->setDirection('OUT');
            $outgoingMessage->setStatus('complete');

            $this->em->persist($outgoingMessage);
            
            // Update incoming message status
            $incomingMessage->setStatus('complete');
            
            $this->em->flush();

            $this->logger->info('Message processed', [
                'user_id' => $user->getId(),
                'message_id' => $outgoingMessage->getId(),
                'provider' => $aiResponse['provider'] ?? 'test'
            ]);

            // Get Again data for response
            $againData = $this->getAgainData($incomingMessage->getTopic(), null);

            return $this->json([
                'success' => true,
                'message' => [
                    'id' => $outgoingMessage->getId(),
                    'text' => $outgoingMessage->getText(),
                    'hasFile' => (bool) $outgoingMessage->getFile(),
                    'filePath' => $outgoingMessage->getFilePath(),
                    'fileType' => $outgoingMessage->getFileType(),
                    'provider' => $outgoingMessage->getProviderIndex(),
                    'timestamp' => $outgoingMessage->getUnixTimestamp(),
                    'trackId' => $outgoingMessage->getTrackingId(),
                    'topic' => $incomingMessage->getTopic(),
                ],
                'again' => $againData
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Message processing failed', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'error' => 'Message processing failed',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/history', name: 'history', methods: ['GET'])]
    public function getHistory(
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $limit = $request->query->get('limit', 50);
        $trackId = $request->query->get('trackId');

        $qb = $this->em->createQueryBuilder();
        $qb->select('m')
            ->from(Message::class, 'm')
            ->where('m.userId = :userId')
            ->setParameter('userId', $user->getId())
            ->orderBy('m.unixTimestamp', 'DESC')
            ->setMaxResults($limit);

        if ($trackId) {
            $qb->andWhere('m.trackingId = :trackId')
                ->setParameter('trackId', $trackId);
        }

        $messages = $qb->getQuery()->getResult();

        $result = array_map(function (Message $m) {
            return [
                'id' => $m->getId(),
                'text' => $m->getText(),
                'direction' => $m->getDirection(),
                'hasFile' => (bool) $m->getFile(),
                'filePath' => $m->getFilePath(),
                'fileType' => $m->getFileType(),
                'provider' => $m->getProviderIndex(),
                'timestamp' => $m->getUnixTimestamp(),
                'topic' => $m->getTopic(),
                'language' => $m->getLanguage(),
                'trackId' => $m->getTrackingId()
            ];
        }, $messages);

        return $this->json([
            'success' => true,
            'messages' => array_reverse($result) // Oldest first
        ]);
    }

    /**
     * Enhance user input with AI
     */
    #[Route('/enhance', name: 'enhance', methods: ['POST'])]
    public function enhanceInput(
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $inputText = $data['text'] ?? '';

        if (empty($inputText)) {
            return $this->json(['error' => 'Text is required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->logger->info('Enhancement request started', [
                'user_id' => $user->getId(),
                'text_length' => strlen($inputText)
            ]);

            // Get enhance prompt
            $promptData = $this->promptService->getPrompt('tools:enhance', 'en', 0);
            $systemPrompt = $promptData['BPROMPT'];
            
            $this->logger->info('Enhancement prompt loaded', [
                'prompt_id' => $promptData['BID'],
                'prompt_length' => strlen($systemPrompt)
            ]);
            
            // Resolve model for user (wie im ChatHandler)
            $modelId = $this->modelConfigService->getDefaultModel('CHAT', $user->getId());
            $provider = null;
            $modelName = null;
            
            if ($modelId) {
                $provider = $this->modelConfigService->getProviderForModel($modelId);
                $modelName = $this->modelConfigService->getModelName($modelId);
                
                $this->logger->info('Enhancement model resolved', [
                    'model_id' => $modelId,
                    'provider' => $provider,
                    'model' => $modelName
                ]);
            }
            
            $response = $this->aiFacade->chat(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $inputText]
                ],
                $user->getId(),
                [
                    'provider' => $provider,
                    'model' => $modelName,
                    'temperature' => 0.7
                ]
            );

            $this->logger->info('Enhancement response received', [
                'response_length' => strlen($response['content'] ?? '')
            ]);

            $enhancedText = trim($response['content'] ?? $inputText);

            return $this->json([
                'success' => true,
                'original' => $inputText,
                'enhanced' => $enhancedText
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Enhancement failed', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return $this->json([
                'error' => 'Enhancement failed',
                'message' => $e->getMessage(),
                'debug' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Send "Again" request with specific model/prompt
     */
    #[Route('/again', name: 'again', methods: ['POST'])]
    public function sendAgain(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        
        try {
            $result = $this->againHandler->processAgainRequest($user, $data);
            return $this->json($result);
        } catch (\Exception $e) {
            $this->logger->error('Again request failed', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage()
            ]);
            return $this->json([
                'error' => 'Again request failed: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Enqueue message for async processing (Fast-Ack < 300ms)
     */
    #[Route('/enqueue', name: 'enqueue', methods: ['POST'])]
    public function enqueueMessage(
        Request $request,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $messageText = $data['message'] ?? '';

        if (empty($messageText)) {
            return $this->json(['error' => 'Message is required'], Response::HTTP_BAD_REQUEST);
        }

        // Enqueue message (Fast-Ack)
        $result = $this->enqueueService->enqueueMessage(
            $user,
            $messageText,
            [
                'tracking_id' => $data['trackId'] ?? time(),
                'reasoning' => $data['reasoning'] ?? false,
            ]
        );

        return $this->json($result, Response::HTTP_ACCEPTED);
    }

    /**
     * Check message status (Polling)
     */
    #[Route('/{messageId}/status', name: 'status', methods: ['GET'])]
    public function getMessageStatus(
        int $messageId,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $status = $this->enqueueService->getMessageStatus($messageId);

        if (!$status) {
            return $this->json(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($status);
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
