<?php

namespace App\Controller;

use App\Entity\Chat;
use App\Entity\File;
use App\Entity\Message;
use App\Repository\ChatRepository;
use App\Repository\FileRepository;
use App\Repository\MessageRepository;
use App\Service\File\FileProcessor;
use App\Service\File\FileStorageService;
use App\Service\File\VectorizationService;
use App\Service\Message\MessageProcessor;
use App\Service\RateLimitService;
use App\Service\WidgetService;
use App\Service\WidgetSessionService;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Widget Public API Controller.
 *
 * PUBLIC endpoints (no authentication required) for chat widgets embedded on external websites
 */
#[Route('/api/v1/widget', name: 'api_widget_public_')]
#[OA\Tag(name: 'Widget (Public)')]
class WidgetPublicController extends AbstractController
{
    public function __construct(
        private WidgetService $widgetService,
        private WidgetSessionService $sessionService,
        private MessageProcessor $messageProcessor,
        private RateLimitService $rateLimitService,
        private FileStorageService $storageService,
        private FileProcessor $fileProcessor,
        private VectorizationService $vectorizationService,
        private ChatRepository $chatRepository,
        private MessageRepository $messageRepository,
        private FileRepository $fileRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private string $uploadDir,
    ) {
    }

    /**
     * Get widget configuration.
     *
     * PUBLIC endpoint - no authentication required
     */
    #[Route('/{widgetId}/config', name: 'config', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/widget/{widgetId}/config',
        summary: 'Get widget configuration (public)',
        tags: ['Widget (Public)']
    )]
    #[OA\Parameter(
        name: 'widgetId',
        in: 'path',
        required: true,
        schema: new OA\Schema(type: 'string', example: 'wdg_abc123...')
    )]
    #[OA\Response(
        response: 200,
        description: 'Widget configuration',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'widgetId', type: 'string'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'config', type: 'object'),
                new OA\Property(property: 'isActive', type: 'boolean'),
            ]
        )
    )]
    public function config(string $widgetId, Request $request): JsonResponse
    {
        $widget = $this->widgetService->getWidgetById($widgetId);

        if (!$widget) {
            return $this->json([
                'error' => 'Widget not found',
            ], Response::HTTP_NOT_FOUND);
        }

        $isActive = $this->widgetService->isWidgetActive($widget);

        if (!$isActive) {
            return $this->json([
                'error' => 'Widget is not active',
                'reason' => 'owner_limits_exceeded',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $config = $widget->getConfig();
        if ($domainError = $this->ensureDomainAllowed($config, $request, $widget->getOwnerId())) {
            return $domainError;
        }

        return $this->json([
            'success' => true,
            'widgetId' => $widget->getWidgetId(),
            'name' => $widget->getName(),
            'config' => $config,
            'isActive' => true,
        ]);
    }

    /**
     * Send message to widget (synchronous response).
     *
     * PUBLIC endpoint - no authentication required, session-based rate limiting
     */
    #[Route('/{widgetId}/message', name: 'message', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/widget/{widgetId}/message',
        summary: 'Send message to widget (public, streaming)',
        tags: ['Widget (Public)']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['sessionId', 'text'],
            properties: [
                new OA\Property(property: 'sessionId', type: 'string', example: 'sess_xyz123...'),
                new OA\Property(property: 'text', type: 'string', example: 'Hello, I need help!'),
                new OA\Property(property: 'chatId', type: 'integer', nullable: true),
                new OA\Property(property: 'files', type: 'array', items: new OA\Items(type: 'integer')),
            ]
        )
    )]
    public function message(string $widgetId, Request $request): Response
    {
        $data = json_decode($request->getContent(), true);

        // Immediate test to see if we reach here
        if (null === $data) {
            return $this->json([
                'error' => 'Invalid JSON in request body',
            ], Response::HTTP_BAD_REQUEST);
        }

        error_log('📥 Widget message request: '.json_encode($data));

        if (empty($data['sessionId']) || empty($data['text'])) {
            error_log('❌ Missing fields - sessionId: '.($data['sessionId'] ?? 'NULL').', text: '.($data['text'] ?? 'NULL'));

            return $this->json([
                'error' => 'Missing required fields: sessionId, text',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Get widget
        $widget = $this->widgetService->getWidgetById($widgetId);
        if (!$widget) {
            error_log('❌ Widget not found: '.$widgetId);

            return $this->json(['error' => 'Widget not found'], Response::HTTP_NOT_FOUND);
        }

        error_log('✅ Widget found: '.$widget->getName());

        // Check if widget is active
        if (!$this->widgetService->isWidgetActive($widget)) {
            return $this->json([
                'error' => 'Widget is not active',
                'reason' => 'owner_limits_exceeded',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $config = $widget->getConfig();
        if ($domainError = $this->ensureDomainAllowed($config, $request, $widget->getOwnerId())) {
            return $domainError;
        }

        // Validate test mode: only allow if authenticated user is widget owner
        $isValidatedTestMode = $this->isValidatedTestMode($request, $widget->getOwnerId());

        // Get or create session
        $session = $this->sessionService->getOrCreateSession($widgetId, $data['sessionId'], $isValidatedTestMode);

        // Check session limits
        $messageLimit = (int) ($config['messageLimit'] ?? WidgetSessionService::DEFAULT_MAX_MESSAGES);
        $limitCheck = $this->sessionService->checkSessionLimit($session, $messageLimit);
        if (!$limitCheck['allowed']) {
            return $this->json([
                'error' => 'Rate limit exceeded',
                'reason' => $limitCheck['reason'],
                'remaining' => $limitCheck['remaining'],
                'retryAfter' => $limitCheck['retry_after'],
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        // Check owner's limits
        $ownerId = $widget->getOwnerId();
        if (!$ownerId) {
            return $this->json(['error' => 'Widget owner not found'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Get owner entity (we need it for the user entity)
        $owner = $this->em->getRepository(\App\Entity\User::class)->find($ownerId);
        if (!$owner) {
            return $this->json(['error' => 'Widget owner not found'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        try {
            // Resolve chat for this session
            $sessionChatId = $session->getChatId();
            $chat = null;

            if ($sessionChatId) {
                $chat = $this->chatRepository->find($sessionChatId);
                if (!$chat || $chat->getUserId() !== $owner->getId()) {
                    $chat = null;
                }
            }

            if (!$chat && !empty($data['chatId'])) {
                $chat = $this->chatRepository->find((int) $data['chatId']);
                if (!$chat || $chat->getUserId() !== $owner->getId()) {
                    $chat = null;
                }
            }

            if (!$chat) {
                $now = new \DateTimeImmutable();
                $chat = new Chat();
                $chat->setUserId($owner->getId());
                $sessionSuffix = substr($session->getSessionId(), -6);
                $chat->setTitle(sprintf('Widget: %s • %s', $widget->getName(), $sessionSuffix));
                $chat->setCreatedAt($now);
                $chat->setUpdatedAt($now);
                $this->em->persist($chat);
                $this->em->flush();

                // Create welcome message if configured
                $autoMessage = $config['autoMessage'] ?? '';
                if (!empty($autoMessage)) {
                    // Use timestamp 10 seconds before current time to ensure welcome message comes first.
                    // This is combined with secondary sort by ID in MessageRepository for reliable ordering.
                    $welcomeTimestamp = time() - 10;
                    $welcomeMessage = new Message();
                    $welcomeMessage->setUserId($owner->getId());
                    $welcomeMessage->setChat($chat);
                    $welcomeMessage->setText($autoMessage);
                    $welcomeMessage->setDirection('OUT');
                    $welcomeMessage->setStatus('complete');
                    $welcomeMessage->setMessageType('WDGT');
                    $welcomeMessage->setTrackingId($welcomeTimestamp);
                    $welcomeMessage->setUnixTimestamp($welcomeTimestamp);
                    $welcomeMessage->setDateTime(date('YmdHis', $welcomeTimestamp));
                    $welcomeMessage->setProviderIndex('widget');
                    $this->em->persist($welcomeMessage);
                    $this->em->flush();

                    $this->logger->info('Widget welcome message created', [
                        'widget_id' => $widget->getWidgetId(),
                        'chat_id' => $chat->getId(),
                    ]);
                }

                $this->logger->info('Widget chat created', [
                    'widget_id' => $widget->getWidgetId(),
                    'chat_id' => $chat->getId(),
                    'session_id' => $session->getSessionId(),
                ]);
            } else {
                $chat->updateTimestamp();
                $this->em->flush();
            }

            // Create incoming message
            $incomingMessage = new Message();
            $incomingMessage->setUserId($owner->getId());
            $incomingMessage->setChat($chat);
            $incomingMessage->setText($data['text']);
            $incomingMessage->setDirection('IN');
            $incomingMessage->setStatus('processing');
            $incomingMessage->setMessageType('WDGT');
            $incomingMessage->setTrackingId(time());
            $incomingMessage->setUnixTimestamp(time());
            $incomingMessage->setDateTime(date('YmdHis'));
            $incomingMessage->setProviderIndex('widget'); // Special provider index for widgets

            $this->em->persist($incomingMessage);
            $this->em->flush();

            // Attach uploaded files if provided
            $fileIds = [];
            if (!empty($data['files']) && is_array($data['files'])) {
                foreach ($data['files'] as $fileId) {
                    $fileId = (int) $fileId;
                    if ($fileId <= 0) {
                        continue;
                    }

                    $file = $this->fileRepository->find($fileId);
                    if (!$file) {
                        continue;
                    }

                    // Ensure file belongs to this session
                    if ($file->getUserSessionId() !== $session->getId()) {
                        continue;
                    }

                    $incomingMessage->addFile($file);
                    $file->setStatus('attached');
                    $fileIds[] = $fileId;
                }

                if (!empty($fileIds)) {
                    $incomingMessage->setFile(count($fileIds));
                    $this->em->flush();
                }
            }

            // Increment session message count
            $this->sessionService->incrementMessageCount($session);
            $this->sessionService->attachChat($session, $chat);

            \set_time_limit(0);

            $processingOptions = [
                'fixed_task_prompt' => $widget->getTaskPromptTopic(),
                'skipSorting' => true,
                'channel' => 'WIDGET',
                'language' => 'en',
                'rag_group_key' => sprintf('WIDGET:%s', $widget->getWidgetId()),
                'rag_limit' => (int) ($config['ragResultLimit'] ?? 5),
                'rag_min_score' => isset($config['ragMinScore'])
                    ? max(0.0, min(1.0, (float) $config['ragMinScore']))
                    : 0.3,
                'widget_id' => $widget->getWidgetId(),
                'is_widget_mode' => true, // Disable memories for widget
            ];

            $response = new StreamedResponse(function () use (
                $incomingMessage,
                $processingOptions,
                $owner,
                $chat,
                $fileIds,
                $widgetId
            ) {
                $responseText = '';
                $reasoningBuffer = '';
                $hasReasoningStarted = false;
                $jsonBuffer = '';
                $isBufferingJson = false;
                $chunkCount = 0;

                try {
                    $result = $this->messageProcessor->processStream(
                        $incomingMessage,
                        function ($chunk) use (
                            &$responseText,
                            &$reasoningBuffer,
                            &$hasReasoningStarted,
                            &$jsonBuffer,
                            &$isBufferingJson,
                            &$chunkCount
                        ) {
                            if (connection_aborted()) {
                                throw new \RuntimeException('Client disconnected');
                            }

                            $sendChunk = function (string $content) use (&$responseText) {
                                if ('' === $content) {
                                    return;
                                }

                                $responseText .= $content;
                                $this->sendSse('data', ['chunk' => $content]);
                            };

                            if (is_array($chunk)) {
                                $type = $chunk['type'] ?? 'content';
                                $content = $chunk['content'] ?? '';

                                if ('reasoning' === $type) {
                                    if (!$hasReasoningStarted) {
                                        $reasoningBuffer = '<think>';
                                        $hasReasoningStarted = true;
                                    }
                                    $reasoningBuffer .= $content;

                                    return;
                                }

                                if ($hasReasoningStarted) {
                                    $reasoningBuffer .= '</think>';
                                    $sendChunk($reasoningBuffer);
                                    $reasoningBuffer = '';
                                    $hasReasoningStarted = false;
                                }

                                $sendChunk($content);

                                return;
                            }

                            if ($hasReasoningStarted) {
                                $reasoningBuffer .= '</think>';
                                $sendChunk($reasoningBuffer);
                                $reasoningBuffer = '';
                                $hasReasoningStarted = false;
                            }

                            $chunkStr = (string) $chunk;

                            if (0 === $chunkCount && !$isBufferingJson) {
                                $trimmed = trim($chunkStr);
                                if ('' !== $trimmed && str_starts_with($trimmed, '{')) {
                                    $isBufferingJson = true;
                                    $jsonBuffer = $chunkStr;
                                    ++$chunkCount;

                                    return;
                                }
                            }

                            if ($isBufferingJson) {
                                $jsonBuffer .= $chunkStr;

                                if (str_contains($jsonBuffer, '}')) {
                                    $trimmed = trim($jsonBuffer);
                                    $lastBrace = strrpos($trimmed, '}');
                                    if (false !== $lastBrace) {
                                        $potentialJson = substr($trimmed, 0, $lastBrace + 1);
                                        $potentialJson = preg_replace('/"BFILE":\s*\n/', '"BFILE": 0'."\n", $potentialJson);
                                        $potentialJson = preg_replace('/"BFILE":\s*\r\n/', '"BFILE": 0'."\r\n", $potentialJson);
                                        $potentialJson = preg_replace('/"BFILE":\s*}/', '"BFILE": 0}', $potentialJson);

                                        try {
                                            $jsonData = json_decode($potentialJson, true, 512, JSON_THROW_ON_ERROR);
                                            if (isset($jsonData['BTEXT'])) {
                                                $extractedText = $jsonData['BTEXT'];
                                                $sendChunk($extractedText);
                                                $isBufferingJson = false;
                                                $jsonBuffer = '';
                                                ++$chunkCount;

                                                return;
                                            }
                                        } catch (\JsonException $e) {
                                            // Keep buffering until valid JSON
                                        }
                                    }
                                }

                                ++$chunkCount;

                                return;
                            }

                            if ('' !== $chunkStr) {
                                $sendChunk($chunkStr);
                            }

                            ++$chunkCount;
                        },
                        function ($statusUpdate) {
                            if (!is_array($statusUpdate)) {
                                return;
                            }

                            $status = $statusUpdate['status'] ?? 'status';
                            $payload = [];
                            if (isset($statusUpdate['message'])) {
                                $payload['message'] = $statusUpdate['message'];
                            }
                            if (!empty($statusUpdate['metadata'])) {
                                $payload['metadata'] = $statusUpdate['metadata'];
                            }

                            $this->sendSse($status, $payload);
                        },
                        $processingOptions
                    );

                    if (!($result['success'] ?? false)) {
                        $errorMessage = $result['error'] ?? 'Processing failed';
                        $incomingMessage->setStatus('failed');
                        $this->em->flush();

                        $this->sendSse('error', ['error' => $errorMessage]);

                        return;
                    }

                    if ($hasReasoningStarted && '' !== $reasoningBuffer) {
                        $reasoningBuffer .= '</think>';
                        $responseText .= $reasoningBuffer;
                        $this->sendSse('data', ['chunk' => $reasoningBuffer]);
                    }

                    $responseMetadata = $result['response']['metadata'] ?? [];
                    $tokens = $responseMetadata['tokens'] ?? 0;
                    if (is_array($tokens)) {
                        $tokens = array_sum(array_map(static fn ($value) => is_numeric($value) ? (int) $value : 0, $tokens));
                    }

                    // Get classification data (topic, language) from result
                    $classification = $result['classification'] ?? [];
                    $topic = $classification['topic'] ?? 'WIDGET';
                    $language = $classification['language'] ?? 'en';

                    // Update incoming message with classification
                    $incomingMessage->setTopic($topic);
                    $incomingMessage->setLanguage($language);
                    $incomingMessage->setStatus('complete');

                    $outgoingMessage = new Message();
                    $outgoingMessage->setUserId($owner->getId());
                    $outgoingMessage->setChat($chat);
                    $outgoingMessage->setTrackingId($incomingMessage->getTrackingId());
                    $outgoingMessage->setProviderIndex($responseMetadata['provider'] ?? 'AI_WIDGET');
                    $outgoingMessage->setUnixTimestamp(time());
                    $outgoingMessage->setDateTime(date('YmdHis'));
                    $outgoingMessage->setMessageType('WDGT');
                    $outgoingMessage->setFile(0);
                    $outgoingMessage->setFilePath('');
                    $outgoingMessage->setFileType('');
                    $outgoingMessage->setTopic($topic);
                    $outgoingMessage->setLanguage($language);
                    $outgoingMessage->setText($responseText);
                    $outgoingMessage->setDirection('OUT');
                    $outgoingMessage->setStatus('complete');

                    $this->em->persist($outgoingMessage);
                    $this->em->flush();

                    $this->rateLimitService->recordUsage($owner, 'MESSAGES', [
                        'provider' => $responseMetadata['provider'] ?? null,
                        'model' => $responseMetadata['model'] ?? null,
                        'tokens' => $tokens,
                        'channel' => 'WIDGET',
                        'files' => $fileIds,
                    ]);

                    $this->sendSse('complete', [
                        'messageId' => $incomingMessage->getId(),
                        'chatId' => $chat->getId(),
                        'metadata' => [
                            'response' => $responseMetadata,
                            'classification' => $result['classification'] ?? null,
                            'preprocessed' => $result['preprocessed'] ?? null,
                            'search_results' => $result['search_results'] ?? null,
                            'files' => $fileIds,
                        ],
                    ]);
                } catch (\Throwable $e) {
                    $this->logger->error('Widget message streaming failed', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'widget_id' => $widgetId,
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]);

                    try {
                        $incomingMessage->setStatus('failed');
                        $this->em->flush();
                    } catch (\Throwable $flushException) {
                        // EntityManager might be closed after database error
                        $this->logger->warning('Could not update message status after error', [
                            'error' => $flushException->getMessage(),
                        ]);
                    }

                    $this->sendSse('error', [
                        'error' => 'Failed to process message',
                        'details' => [
                            'message' => $e->getMessage(),
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                        ],
                    ]);
                }
            });

            $response->headers->set('Content-Type', 'text/event-stream; charset=utf-8');
            $response->headers->set('Cache-Control', 'no-cache');
            $response->headers->set('Connection', 'keep-alive');
            $response->headers->set('X-Accel-Buffering', 'no');

            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Widget message failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'widget_id' => $widgetId,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            // DEBUG: Return detailed error (REMOVE IN PRODUCTION!)
            return $this->json([
                'error' => 'Failed to process message',
                'debug' => [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => explode("\n", $e->getTraceAsString()),
                ],
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Upload file for widget session.
     *
     * PUBLIC endpoint - no authentication required
     * Uses same file processing workflow as authenticated uploads
     * Files are stored with BUSERID=0 and BUSERSESSIONID=session_id
     */
    #[Route('/{widgetId}/upload', name: 'upload', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/widget/{widgetId}/upload',
        summary: 'Upload file for widget (public)',
        tags: ['Widget (Public)']
    )]
    public function upload(string $widgetId, Request $request): JsonResponse
    {
        $sessionId = $request->headers->get('X-Widget-Session');

        if (!$sessionId) {
            return $this->json([
                'error' => 'X-Widget-Session header required',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Get widget and verify it exists + is active
        $widget = $this->widgetService->getWidgetById($widgetId);
        if (!$widget) {
            return $this->json(['error' => 'Widget not found'], Response::HTTP_NOT_FOUND);
        }

        $isActive = $this->widgetService->isWidgetActive($widget);
        if (!$isActive) {
            return $this->json([
                'error' => 'Widget is not active',
                'reason' => 'owner_limits_exceeded',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // Check domain whitelist
        $config = $widget->getConfig();
        if ($domainError = $this->ensureDomainAllowed($config, $request, $widget->getOwnerId())) {
            return $domainError;
        }

        // Check if file upload is enabled for this widget
        if (!($config['allowFileUpload'] ?? false)) {
            return $this->json([
                'error' => 'File upload is disabled for this widget',
            ], Response::HTTP_FORBIDDEN);
        }

        // Validate test mode: only allow if authenticated user is widget owner
        $isValidatedTestMode = $this->isValidatedTestMode($request, $widget->getOwnerId());

        // Get or create widget session
        $widgetSession = $this->sessionService->getOrCreateSession($widgetId, $sessionId, $isValidatedTestMode);

        $owner = $widget->getOwner();
        if (!$owner) {
            $owner = $this->em->getRepository(\App\Entity\User::class)->find($widget->getOwnerId());
        }

        $fileLimit = (int) ($config['fileUploadLimit'] ?? WidgetSessionService::DEFAULT_MAX_FILES);
        $fileLimitCheck = $this->sessionService->checkFileUploadLimit($widgetSession, $fileLimit);
        if (!$fileLimitCheck['allowed']) {
            return $this->json([
                'error' => 'File upload limit reached',
                'reason' => $fileLimitCheck['reason'],
                'remaining' => $fileLimitCheck['remaining'] ?? 0,
                'max' => $fileLimitCheck['max_files'] ?? $fileLimit,
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        // Get uploaded file
        $uploadedFile = $request->files->get('file');
        if (!$uploadedFile) {
            return $this->json([
                'error' => 'No file uploaded. Use form-data with file field',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Check FILE_ANALYSIS rate limit for widget owner
        if ($owner) {
            $fileAnalysisLimit = $this->rateLimitService->checkLimit($owner, 'FILE_ANALYSIS');
            if (!($fileAnalysisLimit['allowed'] ?? true)) {
                return $this->json([
                    'error' => 'Rate limit exceeded for FILE_ANALYSIS',
                    'rate_limit_exceeded' => true,
                    'action' => 'FILE_ANALYSIS',
                    'used' => $fileAnalysisLimit['used'],
                    'limit' => $fileAnalysisLimit['limit'],
                ], Response::HTTP_TOO_MANY_REQUESTS);
            }
        }

        // Check file size limit (from widget config)
        $maxFileSize = ($config['maxFileSize'] ?? 10) * 1024 * 1024; // MB to bytes
        if ($uploadedFile->getSize() > $maxFileSize) {
            return $this->json([
                'error' => 'File too large',
                'max_size_mb' => $config['maxFileSize'] ?? 10,
            ], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        try {
            // Process file using same workflow as authenticated uploads
            $result = $this->processWidgetFile(
                $uploadedFile,
                $widget,
                $widgetSession
            );

            if ($result['success']) {
                $this->sessionService->incrementFileCount($widgetSession);

                // Record FILE_ANALYSIS usage for widget owner
                if ($owner) {
                    $this->rateLimitService->recordUsage($owner, 'FILE_ANALYSIS', [
                        'file_id' => $result['file']['id'],
                        'widget_id' => $widgetId,
                        'session_id' => $sessionId,
                        'filename' => $uploadedFile->getClientOriginalName(),
                        'source' => 'WIDGET',
                    ]);
                }

                $maxFilesForSession = $fileLimitCheck['max_files'] ?? $fileLimit;
                $remainingUploads = $maxFilesForSession <= 0
                    ? null
                    : max(0, $maxFilesForSession - $widgetSession->getFileCount());

                return $this->json($result + [
                    'remainingUploads' => $remainingUploads,
                ]);
            } else {
                return $this->json($result, Response::HTTP_BAD_REQUEST);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Widget file upload failed', [
                'widget_id' => $widgetId,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'error' => 'File upload failed: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Process uploaded file for widget session
     * Same workflow as FileController but for anonymous widget users.
     */
    private function processWidgetFile($uploadedFile, $widget, $widgetSession): array
    {
        $fileExtension = strtolower($uploadedFile->getClientOriginalExtension());
        $owner = $widget->getOwner();

        if (!$owner) {
            return [
                'success' => false,
                'error' => 'Widget owner not found',
            ];
        }

        // Step 1: Store file
        $storageResult = $this->storageService->storeUploadedFile(
            $uploadedFile,
            $owner->getId()
        );

        $relativePath = $storageResult['path'];

        // Create File entity with BUSERID=0 and BUSERSESSIONID=session_id
        $file = new File();
        $file->setUserId(0); // Anonymous widget user
        $file->setUserSessionId($widgetSession->getId()); // Link to widget session
        $file->setFilePath($relativePath);
        $file->setFileName($uploadedFile->getClientOriginalName());
        $file->setFileSize($storageResult['size']);
        $file->setFileMime($storageResult['mime']);
        $file->setFileType($this->getFileTypeString($fileExtension));

        $this->em->persist($file);
        $this->em->flush();

        $result = [
            'success' => true,
            'file' => [
                'id' => $file->getId(),
                'filename' => $uploadedFile->getClientOriginalName(),
                'size' => $storageResult['size'],
            ],
        ];

        // Step 2: Extract text
        try {
            [$extractedText, $extractMeta] = $this->fileProcessor->extractText(
                $relativePath,
                $fileExtension,
                $owner->getId()
            );

            $file->setFileText($extractedText);
            $file->setStatus('extracted');
            $this->em->flush();

            $result['file']['extracted_text_length'] = strlen($extractedText);
            $result['extraction_strategy'] = $extractMeta['strategy'] ?? 'unknown';
        } catch (\Throwable $e) {
            $this->logger->error('Widget file extraction failed', [
                'file_id' => $file->getId(),
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Text extraction failed: '.$e->getMessage(),
            ];
        }

        // Step 3: Vectorize (for widget owner's RAG, grouped by widget)
        try {
            $groupKey = "WIDGET:{$widget->getWidgetId()}";

            $vectorResult = $this->vectorizationService->vectorizeAndStore(
                $extractedText,
                $owner->getId(), // Store under owner for quota/usage
                $file->getId(),
                $groupKey,
                $this->getFileTypeCode($fileExtension)
            );

            if ($vectorResult['success']) {
                $file->setStatus('vectorized');
                $this->em->flush();

                $result['file']['chunks_created'] = $vectorResult['chunks_created'];
                $result['vectorized'] = true;
            } else {
                $this->logger->warning('Widget file vectorization failed', [
                    'file_id' => $file->getId(),
                    'error' => $vectorResult['error'],
                ]);

                $result['vectorized'] = false;
                $result['vectorization_error'] = $vectorResult['error'];
            }
        } catch (\Throwable $e) {
            $this->logger->error('Widget file vectorization exception', [
                'file_id' => $file->getId(),
                'error' => $e->getMessage(),
            ]);

            $result['vectorized'] = false;
            $result['vectorization_error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Get file type string for File entity (BFILETYPE column).
     */
    private function getFileTypeString(string $extension): string
    {
        $typeMap = [
            'pdf' => 'document',
            'doc' => 'document', 'docx' => 'document',
            'xls' => 'spreadsheet', 'xlsx' => 'spreadsheet',
            'ppt' => 'presentation', 'pptx' => 'presentation',
            'txt' => 'text', 'md' => 'text',
            'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image',
            'mp4' => 'video', 'mov' => 'video', 'avi' => 'video',
            'mp3' => 'audio', 'wav' => 'audio',
        ];

        return $typeMap[$extension] ?? 'other';
    }

    /**
     * Get file type code for RAG vectorization (matches FileController logic).
     */
    private function getFileTypeCode(string $extension): int
    {
        return match (strtolower($extension)) {
            'txt', 'md', 'csv' => 0, // Plain text
            'jpg', 'jpeg', 'png', 'gif', 'webp' => 1, // Image
            'mp3', 'mp4', 'wav', 'ogg', 'm4a', 'webm' => 2, // Audio/Video
            'pdf' => 3, // PDF
            'docx', 'doc', 'xlsx', 'xls', 'pptx', 'ppt' => 4, // Office
            default => 5, // Other
        };
    }

    /**
     * Get conversation history for a widget session.
     *
     * Restores chats after page reloads (PUBLIC).
     */
    #[Route('/{widgetId}/history', name: 'history', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/widget/{widgetId}/history',
        summary: 'Get widget chat history for a session',
        tags: ['Widget (Public)']
    )]
    public function history(string $widgetId, Request $request): JsonResponse
    {
        $sessionId = $request->query->getString('sessionId');

        if ('' === $sessionId) {
            return $this->json([
                'error' => 'sessionId is required',
            ], Response::HTTP_BAD_REQUEST);
        }

        $widget = $this->widgetService->getWidgetById($widgetId);
        if (!$widget) {
            return $this->json([
                'error' => 'Widget not found',
            ], Response::HTTP_NOT_FOUND);
        }

        $config = $widget->getConfig();
        if ($domainError = $this->ensureDomainAllowed($config, $request, $widget->getOwnerId())) {
            return $domainError;
        }

        $session = $this->sessionService->getSession($widgetId, $sessionId);
        if (!$session) {
            return $this->json([
                'success' => true,
                'chatId' => null,
                'messages' => [],
                'session' => [
                    'sessionId' => $sessionId,
                    'messageCount' => 0,
                    'fileCount' => 0,
                    'lastMessage' => null,
                ],
            ]);
        }

        $chatId = $session->getChatId();
        if (!$chatId) {
            return $this->json([
                'success' => true,
                'chatId' => null,
                'messages' => [],
                'session' => [
                    'sessionId' => $session->getSessionId(),
                    'messageCount' => $session->getMessageCount(),
                    'fileCount' => $session->getFileCount(),
                    'lastMessage' => $session->getLastMessage() ?: null,
                ],
            ]);
        }

        $chat = $this->chatRepository->find($chatId);
        if (!$chat || $chat->getUserId() !== $widget->getOwnerId()) {
            return $this->json([
                'success' => true,
                'chatId' => null,
                'messages' => [],
                'session' => [
                    'sessionId' => $session->getSessionId(),
                    'messageCount' => $session->getMessageCount(),
                    'fileCount' => $session->getFileCount(),
                    'lastMessage' => $session->getLastMessage() ?: null,
                ],
            ]);
        }

        $messages = $this->messageRepository->findChatHistory(
            $chat->getUserId(),
            $chat->getId(),
            50,
            20000
        );

        $history = array_map(static function (Message $message) {
            // Include attached files if any
            $filesData = [];
            if ($message->hasFiles()) {
                foreach ($message->getFiles() as $file) {
                    $filesData[] = [
                        'id' => $file->getId(),
                        'filename' => $file->getFileName(),
                        'fileType' => $file->getFileType(),
                        'filePath' => $file->getFilePath(),
                        'fileSize' => $file->getFileSize(),
                        'fileMime' => $file->getFileMime(),
                    ];
                }
            }

            return [
                'id' => $message->getId(),
                'direction' => $message->getDirection(),
                'text' => $message->getText(),
                'timestamp' => $message->getUnixTimestamp(),
                'messageType' => $message->getMessageType(),
                'files' => $filesData,
                'metadata' => [
                    'topic' => $message->getTopic(),
                    'language' => $message->getLanguage(),
                ],
            ];
        }, $messages);

        return $this->json([
            'success' => true,
            'chatId' => $chat->getId(),
            'messages' => $history,
            'session' => [
                'sessionId' => $session->getSessionId(),
                'messageCount' => $session->getMessageCount(),
                'fileCount' => $session->getFileCount(),
                'lastMessage' => $session->getLastMessage() ?: null,
            ],
        ]);
    }

    /**
     * Download a file uploaded via widget.
     *
     * PUBLIC endpoint - validates session ownership instead of user auth.
     */
    #[Route('/{widgetId}/files/{fileId}/download', name: 'file_download', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/widget/{widgetId}/files/{fileId}/download',
        summary: 'Download a widget file',
        tags: ['Widget (Public)']
    )]
    #[OA\Parameter(name: 'widgetId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'fileId', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(name: 'sessionId', in: 'query', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'File content')]
    #[OA\Response(response: 403, description: 'Access denied')]
    #[OA\Response(response: 404, description: 'File not found')]
    public function downloadWidgetFile(string $widgetId, int $fileId, Request $request): Response
    {
        $sessionId = $request->query->getString('sessionId');

        if ('' === $sessionId) {
            return $this->json(['error' => 'Session ID required'], Response::HTTP_BAD_REQUEST);
        }

        // Get the widget
        $widget = $this->widgetService->getWidgetById($widgetId);
        if (!$widget) {
            return $this->json(['error' => 'Widget not found'], Response::HTTP_NOT_FOUND);
        }

        // Check domain restrictions
        $config = $widget->getConfig();
        if ($domainError = $this->ensureDomainAllowed($config, $request, $widget->getOwnerId())) {
            return $domainError;
        }

        // Get the session
        $session = $this->sessionService->getSession($widgetId, $sessionId);
        if (!$session) {
            return $this->json(['error' => 'Session not found'], Response::HTTP_NOT_FOUND);
        }

        // Get the file
        $file = $this->fileRepository->find($fileId);
        if (!$file) {
            return $this->json(['error' => 'File not found'], Response::HTTP_NOT_FOUND);
        }

        // Security: Widget files are uploaded with userId=0 and linked to widget session
        // Verify file was uploaded via widget (userId=0) and belongs to this session
        $isWidgetFile = 0 === $file->getUserId() && $file->getUserSessionId() === $session->getId();
        $isOwnerFile = $file->getUserId() === $widget->getOwnerId();

        if (!$isWidgetFile && !$isOwnerFile) {
            $this->logger->warning('Widget file download: access denied', [
                'file_id' => $fileId,
                'file_user_id' => $file->getUserId(),
                'file_session_id' => $file->getUserSessionId(),
                'widget_session_id' => $session->getId(),
                'widget_owner' => $widget->getOwnerId(),
            ]);

            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        // Get the chat ID from the session
        $chatId = $session->getChatId();
        if (!$chatId) {
            return $this->json(['error' => 'Chat not found'], Response::HTTP_NOT_FOUND);
        }

        // Verify file is attached to a message in this chat
        $fileInChat = $this->messageRepository->isFileInChat($chatId, $fileId);
        if (!$fileInChat) {
            $this->logger->warning('Widget file download: file not in chat', [
                'file_id' => $fileId,
                'chat_id' => $chatId,
            ]);

            return $this->json(['error' => 'File not associated with this chat'], Response::HTTP_FORBIDDEN);
        }

        $filePath = $file->getFilePath();
        if (!$filePath) {
            return $this->json(['error' => 'File path not found'], Response::HTTP_NOT_FOUND);
        }

        $absolutePath = $this->uploadDir.'/'.$filePath;

        if (!file_exists($absolutePath)) {
            $this->logger->error('Widget file download: file not on disk', [
                'file_id' => $fileId,
                'path' => $absolutePath,
            ]);

            return $this->json(['error' => 'File not found on disk'], Response::HTTP_NOT_FOUND);
        }

        // Return file as download
        $response = new \Symfony\Component\HttpFoundation\BinaryFileResponse($absolutePath);
        $response->setContentDisposition(
            \Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $file->getFileName()
        );

        return $response;
    }

    private function ensureDomainAllowed(array $config, Request $request, ?int $widgetOwnerId = null): ?JsonResponse
    {
        // Check for test mode: if X-Widget-Test-Mode header is set
        // and the authenticated user is the widget owner, skip domain check
        if ('true' === $request->headers->get('X-Widget-Test-Mode') && $widgetOwnerId) {
            $user = $this->getUser();
            if ($user && method_exists($user, 'getId') && $user->getId() === $widgetOwnerId) {
                $this->logger->info('Widget domain check bypassed for owner in test mode', [
                    'user_id' => $user->getId(),
                    'widget_owner_id' => $widgetOwnerId,
                ]);

                return null; // Allow - owner is testing their own widget
            }
        }

        $allowedDomains = $config['allowedDomains'] ?? [];
        if (empty($allowedDomains)) {
            $this->logger->warning('Widget request blocked: no domains configured', [
                'allowed_domains_count' => 0,
                'config_keys' => array_keys($config),
                'request_host' => $request->headers->get('X-Widget-Host') ?? $request->getHost(),
            ]);

            return $this->json([
                'error' => 'Domain not allowed',
                'reason' => 'domain_not_whitelisted',
            ], Response::HTTP_FORBIDDEN);
        }

        $host = $this->extractHostFromRequest($request);
        if (!$host) {
            return $this->json([
                'error' => 'Domain not allowed',
                'reason' => 'missing_host',
            ], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isHostAllowed($host, $allowedDomains)) {
            $this->logger->warning('Widget request blocked by domain whitelist', [
                'host' => $host,
                'allowed_domains' => $allowedDomains,
            ]);

            return $this->json([
                'error' => 'Domain not allowed',
                'reason' => 'domain_not_whitelisted',
            ], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function extractHostFromRequest(Request $request): ?string
    {
        $headerHost = $request->headers->get('x-widget-host');
        if ($headerHost) {
            $normalized = $this->normalizeHost($headerHost);
            if ($normalized) {
                return $normalized;
            }
        }

        foreach (['origin', 'referer'] as $header) {
            $value = $request->headers->get($header);
            if (!$value) {
                continue;
            }

            $parts = parse_url($value);
            if (false === $parts || !isset($parts['host'])) {
                continue;
            }

            $host = strtolower($parts['host']);
            if (isset($parts['port'])) {
                $host .= ':'.$parts['port'];
            }

            if ('' !== $host) {
                return $host;
            }
        }

        return null;
    }

    private function normalizeHost(string $value): ?string
    {
        $normalized = strtolower(trim($value));
        if ('' === $normalized) {
            return null;
        }

        $normalized = preg_replace('#^https?://#', '', $normalized);
        $normalized = preg_replace('#^//#', '', $normalized);

        if (null === $normalized) {
            return null;
        }

        $parts = preg_split('~[/?#]~', $normalized);
        $normalized = $parts[0] ?? '';

        return '' !== $normalized ? $normalized : null;
    }

    /**
     * Check whether the provided host matches allowed domains (supports wildcards and optional ports).
     *
     * @param array<string> $allowedDomains
     */
    private function isHostAllowed(string $host, array $allowedDomains): bool
    {
        $host = strtolower($host);
        $hostWithoutPort = $host;
        $hostPort = null;

        if (str_contains($host, ':')) {
            [$hostWithoutPort, $hostPort] = explode(':', $host, 2);
        }

        foreach ($allowedDomains as $domain) {
            if (!is_string($domain) || '' === $domain) {
                continue;
            }

            $domain = strtolower($domain);
            $domainHost = $domain;
            $domainPort = null;

            if (str_contains($domain, ':')) {
                [$domainHost, $domainPort] = explode(':', $domain, 2);
            }

            if (null !== $domainPort && $hostPort !== $domainPort) {
                continue;
            }

            if ($domainHost === $host || $domainHost === $hostWithoutPort) {
                return true;
            }

            if (str_starts_with($domainHost, '*.') && $hostWithoutPort !== $domainHost) {
                $suffix = substr($domainHost, 2);
                if ($suffix && str_ends_with($hostWithoutPort, $suffix)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function sendSse(string $status, array $data = []): void
    {
        if (connection_aborted()) {
            return;
        }

        $event = array_merge(['status' => $status], $this->sanitizeUtf8($data));

        echo 'data: '.json_encode($event, JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }

    private function sanitizeUtf8($value)
    {
        if (is_array($value)) {
            return array_map([$this, 'sanitizeUtf8'], $value);
        }

        if (is_string($value)) {
            return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        return $value;
    }

    /**
     * Validate test mode request.
     *
     * Test mode is only valid if:
     * 1. X-Widget-Test-Mode header is set to 'true'
     * 2. User is authenticated (has valid session/token)
     * 3. Authenticated user is the widget owner
     *
     * This prevents malicious users from marking their sessions as test
     * to avoid being counted in statistics.
     *
     * @param Request $request       The HTTP request
     * @param int     $widgetOwnerId The widget owner's user ID
     *
     * @return bool True if test mode is validated, false otherwise
     */
    private function isValidatedTestMode(Request $request, int $widgetOwnerId): bool
    {
        // Check if test mode is requested
        if ('true' !== $request->headers->get('X-Widget-Test-Mode')) {
            return false;
        }

        // Try to get authenticated user from security context
        $user = $this->getUser();

        // No authenticated user - test mode not allowed
        if (!$user instanceof \App\Entity\User) {
            $this->logger->debug('Test mode rejected: no authenticated user');

            return false;
        }

        // Check if authenticated user is the widget owner
        if ($user->getId() !== $widgetOwnerId) {
            $this->logger->debug('Test mode rejected: user is not widget owner', [
                'user_id' => $user->getId(),
                'widget_owner_id' => $widgetOwnerId,
            ]);

            return false;
        }

        $this->logger->debug('Test mode validated for widget owner', [
            'user_id' => $user->getId(),
        ]);

        return true;
    }
}
