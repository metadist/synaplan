<?php

namespace App\Controller;

use App\AI\Service\AiFacade;
use App\Entity\File;
use App\Entity\Message;
use App\Entity\User;
use App\Service\File\FileProcessor;
use App\Service\File\FileStorageService;
use App\Service\File\VectorizationService;
use App\Service\Message\AgainHandler;
use App\Service\MessageEnqueueService;
use App\Service\ModelConfigService;
use App\Service\PromptService;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
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
        private AgainHandler $againHandler,
        private PromptService $promptService,
        private ModelConfigService $modelConfigService,
        private MessageEnqueueService $enqueueService,
        private RateLimitService $rateLimitService,
        private FileStorageService $fileStorageService,
        private FileProcessor $fileProcessor,
        private VectorizationService $vectorizationService,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/send', name: 'send', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/messages/send',
        summary: 'Send a message and receive AI response',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['message'],
                properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Hello, how are you?'),
                    new OA\Property(property: 'trackId', type: 'integer', example: 1234567890),
                ]
            )
        ),
        tags: ['Messages'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Message processed successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'incomingMessage', type: 'object'),
                        new OA\Property(property: 'outgoingMessage', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid input'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 429, description: 'Rate limit exceeded'),
        ]
    )]
    public function sendMessage(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $messageText = $data['message'] ?? '';
        $trackId = $data['trackId'] ?? time();
        $fileIds = $data['fileIds'] ?? [];

        if (empty($messageText)) {
            return $this->json(['error' => 'Message is required'], Response::HTTP_BAD_REQUEST);
        }

        // Check rate limit
        $rateLimitCheck = $this->rateLimitService->checkLimit($user, 'MESSAGES');
        if (!$rateLimitCheck['allowed']) {
            return $this->json([
                'error' => 'Rate limit exceeded',
                'limit_type' => $rateLimitCheck['limit_type'] ?? 'lifetime',
                'action_type' => 'MESSAGES',
                'limit' => $rateLimitCheck['limit'],
                'used' => $rateLimitCheck['used'],
                'remaining' => $rateLimitCheck['remaining'],
                'reset_at' => $rateLimitCheck['reset_at'] ?? null,
                'user_level' => $user->getUserLevel(),
                'phone_verified' => $user->isEmailVerified(), // Using email verification as proxy
            ], Response::HTTP_TOO_MANY_REQUESTS);
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

            // Link attached files to this message
            if (!empty($fileIds)) {
                $messageFileRepo = $this->em->getRepository(File::class);
                foreach ($fileIds as $fileId) {
                    $messageFile = $messageFileRepo->find($fileId);
                    if ($messageFile && $messageFile->getUserId() === $user->getId()) {
                        // Set message ID to link file to this message
                        $messageFile->setMessageId($incomingMessage->getId());
                        $this->em->persist($messageFile);
                    }
                }
                $this->em->flush();
            }

            // Record usage
            $this->rateLimitService->recordUsage($user, 'MESSAGES');

            // Prepare context with file contents
            $contextMessages = [];

            // Add file contents as context if files are attached
            if (!empty($fileIds)) {
                $messageFileRepo = $this->em->getRepository(File::class);
                $fileContents = [];

                foreach ($fileIds as $fileId) {
                    $messageFile = $messageFileRepo->find($fileId);
                    if ($messageFile && $messageFile->getUserId() === $user->getId()) {
                        $extractedText = $messageFile->getFileText();
                        if ($extractedText) {
                            $fileContents[] = "File: {$messageFile->getFileName()}\n\n{$extractedText}";
                        }
                    }
                }

                if (!empty($fileContents)) {
                    $contextMessages[] = [
                        'role' => 'system',
                        'content' => "The user has attached the following files:\n\n".implode("\n\n---\n\n", $fileContents),
                    ];
                }
            }

            // Add user message
            $contextMessages[] = ['role' => 'user', 'content' => $messageText];

            // Use AI Facade to get response
            $aiResponse = $this->aiFacade->chat(
                $contextMessages,
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
            $outgoingMessage->setProviderIndex($incomingMessage->getProviderIndex()); // Use same channel as incoming
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
            $this->em->flush(); // Flush to get message ID for metadata

            // Store CHAT model information in MessageMeta
            $outgoingMessage->setMeta('ai_chat_provider', $aiResponse['provider'] ?? 'unknown');
            $outgoingMessage->setMeta('ai_chat_model', $aiResponse['model'] ?? 'unknown');
            if (!empty($aiResponse['usage'])) {
                $outgoingMessage->setMeta('ai_chat_usage', json_encode($aiResponse['usage']));
            }

            // NOTE: MessageController doesn't use MessageProcessor, so there's no sorting model info here
            // Only StreamController (which uses MessageProcessor) has sorting model metadata

            // Update incoming message status
            $incomingMessage->setStatus('complete');

            $this->em->flush();

            $this->logger->info('Message processed', [
                'user_id' => $user->getId(),
                'message_id' => $outgoingMessage->getId(),
                'provider' => $aiResponse['provider'] ?? 'test',
            ]);

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
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Message processing failed', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'error' => 'Message processing failed',
                'message' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/history', name: 'history', methods: ['GET'])]
    public function getHistory(
        Request $request,
        #[CurrentUser] ?User $user,
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
            ->addOrderBy('m.id', 'DESC')
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
                'trackId' => $m->getTrackingId(),
            ];
        }, $messages);

        return $this->json([
            'success' => true,
            'messages' => array_reverse($result), // Oldest first
        ]);
    }

    /**
     * Enhance user input with AI.
     */
    #[Route('/enhance', name: 'enhance', methods: ['POST'])]
    public function enhanceInput(
        Request $request,
        #[CurrentUser] ?User $user,
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
                'text_length' => strlen($inputText),
            ]);

            // Get enhance prompt
            $promptData = $this->promptService->getPromptWithMetadata('tools:enhance', 0, 'en');
            if (!$promptData) {
                return $this->json([
                    'error' => 'Enhancement prompt not found',
                ], Response::HTTP_NOT_FOUND);
            }
            $systemPrompt = $promptData['prompt']->getPrompt();

            $this->logger->info('Enhancement prompt loaded', [
                'prompt_id' => $promptData['prompt']->getId(),
                'prompt_length' => strlen($systemPrompt),
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
                    'model' => $modelName,
                ]);
            }

            $response = $this->aiFacade->chat(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $inputText],
                ],
                $user->getId(),
                [
                    'provider' => $provider,
                    'model' => $modelName,
                    'temperature' => 0.7,
                ]
            );

            $this->logger->info('Enhancement response received', [
                'response_length' => strlen($response['content'] ?? ''),
            ]);

            $enhancedText = trim($response['content'] ?? $inputText);

            return $this->json([
                'success' => true,
                'original' => $inputText,
                'enhanced' => $enhancedText,
            ]);
        } catch (\App\AI\Exception\ProviderException $e) {
            $this->logger->warning('Enhancement provider error', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
                'provider' => $e->getProviderName(),
                'context' => $e->getContext(),
            ]);

            // Return user-friendly error message
            return $this->json([
                'error' => 'Enhancement temporarily unavailable',
                'message' => $e->getMessage(),
                'provider' => $e->getProviderName(),
                'context' => $e->getContext(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (\Exception $e) {
            $this->logger->error('Enhancement failed', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return $this->json([
                'error' => 'Enhancement failed',
                'message' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Send "Again" request with specific model/prompt.
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
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'error' => 'Again request failed: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Upload file for chat message (returns file ID for streaming).
     *
     * POST /api/v1/messages/upload-file
     * Form-Data: file (single file)
     *
     * Response: { "success": true, "file_id": 123, "filename": "...", "size": 1024, "mime": "...", "file_type": "pdf" }
     */
    #[Route('/upload-file', name: 'upload_file', methods: ['POST'])]
    public function uploadFileForChat(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $uploadedFile = $request->files->get('file');
        if (!$uploadedFile) {
            return $this->json(['error' => 'No file uploaded'], Response::HTTP_BAD_REQUEST);
        }

        // Check rate limit for FILE_ANALYSIS BEFORE uploading
        $rateLimitCheck = $this->rateLimitService->checkLimit($user, 'FILE_ANALYSIS');
        if (!$rateLimitCheck['allowed']) {
            return $this->json([
                'error' => 'Rate limit exceeded for FILE_ANALYSIS',
                'rate_limit_exceeded' => true,
                'action' => 'FILE_ANALYSIS',
                'used' => $rateLimitCheck['used'],
                'limit' => $rateLimitCheck['limit'],
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            // Store file using FileStorageService
            $storageResult = $this->fileStorageService->storeUploadedFile($uploadedFile, $user->getId());

            if (!$storageResult['success']) {
                return $this->json([
                    'error' => 'File storage failed: '.$storageResult['error'],
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            $relativePath = $storageResult['path'];
            $fileExtension = strtolower($uploadedFile->getClientOriginalExtension());

            // Create File entity (NEW: separate entity for files)
            $messageFile = new File();
            $messageFile->setUserId($user->getId()); // CRITICAL: Set user ID to avoid NULL constraint violation
            $messageFile->setFilePath($relativePath);
            $messageFile->setFileType($fileExtension);
            $messageFile->setFileName($uploadedFile->getClientOriginalName());
            $messageFile->setFileSize($storageResult['size']);
            $messageFile->setFileMime($storageResult['mime']);
            $messageFile->setStatus('uploaded');

            $this->em->persist($messageFile);
            $this->em->flush();

            // CRITICAL: Extract text immediately using FileProcessor!
            $extractMeta = [];
            try {
                [$extractedText, $extractMeta] = $this->fileProcessor->extractText(
                    $relativePath,
                    $fileExtension,
                    $user->getId()
                );

                $messageFile->setFileText($extractedText);
                $messageFile->setStatus('extracted');
                $this->em->flush();

                $this->logger->info('Chat file extracted', [
                    'user_id' => $user->getId(),
                    'file_id' => $messageFile->getId(),
                    'text_length' => strlen($extractedText),
                    'strategy' => $extractMeta['strategy'] ?? 'unknown',
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('Chat file extraction failed', [
                    'user_id' => $user->getId(),
                    'file_id' => $messageFile->getId(),
                    'error' => $e->getMessage(),
                ]);

                $messageFile->setStatus('error');
                $this->em->flush();
            }

            $this->logger->info('Chat file uploaded', [
                'user_id' => $user->getId(),
                'file_id' => $messageFile->getId(),
                'filename' => $uploadedFile->getClientOriginalName(),
                'size' => $storageResult['size'],
                'status' => $messageFile->getStatus(),
            ]);

            // Record FILE_ANALYSIS usage for statistics
            $this->rateLimitService->recordUsage($user, 'FILE_ANALYSIS', [
                'file_id' => $messageFile->getId(),
                'filename' => $uploadedFile->getClientOriginalName(),
                'source' => 'WEB',
            ]);

            $response = [
                'success' => true,
                'file_id' => $messageFile->getId(),
                'filename' => $uploadedFile->getClientOriginalName(),
                'size' => $storageResult['size'],
                'mime' => $storageResult['mime'],
                'file_type' => $fileExtension,
                'extracted_text_length' => strlen($messageFile->getFileText()),
                'status' => $messageFile->getStatus(),
            ];

            // Include transcribed text for audio files (for microphone input)
            if (!empty($messageFile->getFileText()) && in_array($fileExtension, ['ogg', 'mp3', 'wav', 'm4a', 'opus', 'flac', 'webm', 'aac', 'wma', 'mp4', 'avi', 'mov', 'mkv', 'mpeg', 'mpg'])) {
                $response['text'] = $messageFile->getFileText();

                // Include metadata if available from extraction
                if (isset($extractMeta['language'])) {
                    $response['language'] = $extractMeta['language'];
                }
                if (isset($extractMeta['duration'])) {
                    $response['duration'] = $extractMeta['duration'];
                }
            }

            return $this->json($response, Response::HTTP_CREATED);
        } catch (\Exception $e) {
            $this->logger->error('Chat file upload failed', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'error' => 'File upload failed: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Enqueue message for async processing (Fast-Ack < 300ms).
     */
    #[Route('/enqueue', name: 'enqueue', methods: ['POST'])]
    public function enqueueMessage(
        Request $request,
        #[CurrentUser] ?User $user,
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
     * Check message status (Polling).
     */
    #[Route('/{messageId}/status', name: 'status', methods: ['GET'])]
    public function getMessageStatus(
        int $messageId,
        #[CurrentUser] ?User $user,
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
}
