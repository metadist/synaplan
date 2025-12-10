<?php

namespace App\Controller;

use App\AI\Service\AiFacade;
use App\Entity\File;
use App\Entity\Message;
use App\Entity\User;
use App\Service\Message\MessageProcessor;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use App\Service\WidgetService;
use App\Service\WidgetSessionService;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/messages', name: 'api_messages_')]
#[OA\Tag(name: 'Messages')]
class StreamController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private AiFacade $aiFacade,
        private MessageProcessor $messageProcessor,
        private LoggerInterface $logger,
        private ModelConfigService $modelConfigService,
        private WidgetService $widgetService,
        private WidgetSessionService $widgetSessionService,
        private RateLimitService $rateLimitService,
        private string $uploadDir,
    ) {
    }

    #[Route('/stream', name: 'stream', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/messages/stream',
        summary: 'Stream AI chat response',
        description: 'Stream AI chat messages with Server-Sent Events (SSE). Supports reasoning models, web search, and file attachments.',
        security: [['Bearer' => []]],
        tags: ['Messages']
    )]
    #[OA\Parameter(
        name: 'message',
        in: 'query',
        required: true,
        description: 'The message text to send to AI',
        schema: new OA\Schema(type: 'string', example: 'What is the weather today?')
    )]
    #[OA\Parameter(
        name: 'chatId',
        in: 'query',
        required: true,
        description: 'The chat ID to send message to',
        schema: new OA\Schema(type: 'integer', example: 123)
    )]
    #[OA\Parameter(
        name: 'trackId',
        in: 'query',
        required: false,
        description: 'Optional tracking ID for message',
        schema: new OA\Schema(type: 'integer', example: 1234567890)
    )]
    #[OA\Parameter(
        name: 'reasoning',
        in: 'query',
        required: false,
        description: 'Enable reasoning/thinking mode (1 or 0)',
        schema: new OA\Schema(type: 'string', enum: ['0', '1'], example: '1')
    )]
    #[OA\Parameter(
        name: 'webSearch',
        in: 'query',
        required: false,
        description: 'Enable web search (1 or 0)',
        schema: new OA\Schema(type: 'string', enum: ['0', '1'], example: '0')
    )]
    #[OA\Parameter(
        name: 'modelId',
        in: 'query',
        required: false,
        description: 'Specific model ID to use (optional)',
        schema: new OA\Schema(type: 'integer', example: 53)
    )]
    #[OA\Parameter(
        name: 'fileIds',
        in: 'query',
        required: false,
        description: 'Comma-separated list of file IDs to attach',
        schema: new OA\Schema(type: 'string', example: '1,2,3')
    )]
    #[OA\Response(
        response: 200,
        description: 'SSE stream of AI response chunks',
        content: new OA\MediaType(
            mediaType: 'text/event-stream',
            schema: new OA\Schema(
                type: 'string',
                example: "event: data\ndata: {\"chunk\":\"Hello\"}\n\nevent: complete\ndata: {\"status\":\"complete\",\"messageId\":123}\n\n"
            )
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'Not authenticated'
    )]
    public function streamMessage(
        Request $request,
        #[CurrentUser] ?User $user,
    ): Response {
        // Widget-Mode: Check for Widget headers if no authenticated user
        $isWidgetMode = false;
        $widget = null;
        $widgetSession = null;
        $fixedTaskPromptTopic = null;

        if (!$user) {
            $widgetId = $request->headers->get('X-Widget-Id');
            $sessionId = $request->headers->get('X-Widget-Session');

            if ($widgetId && $sessionId) {
                // Widget authentication
                $widget = $this->widgetService->getWidgetById($widgetId);
                if (!$widget) {
                    return $this->json(['error' => 'Widget not found'], Response::HTTP_NOT_FOUND);
                }

                if (!$this->widgetService->isWidgetActive($widget)) {
                    return $this->json([
                        'error' => 'Widget is not active',
                        'reason' => 'owner_limits_exceeded',
                    ], Response::HTTP_SERVICE_UNAVAILABLE);
                }

                // Get or create widget session
                $widgetSession = $this->widgetSessionService->getOrCreateSession($widgetId, $sessionId);

                // Check session rate limits (anonymous limits)
                $limitCheck = $this->widgetSessionService->checkSessionLimit($widgetSession);
                if (!$limitCheck['allowed']) {
                    return $this->json([
                        'error' => 'Rate limit exceeded',
                        'reason' => $limitCheck['reason'],
                        'remaining' => $limitCheck['remaining'],
                        'retryAfter' => $limitCheck['retry_after'],
                    ], Response::HTTP_TOO_MANY_REQUESTS);
                }

                // Get widget owner as the "user" for this request
                $ownerId = $widget->getOwnerId();
                if (!$ownerId) {
                    return $this->json(['error' => 'Widget owner not found'], Response::HTTP_INTERNAL_SERVER_ERROR);
                }

                $user = $this->em->getRepository(User::class)->find($ownerId);
                if (!$user) {
                    return $this->json(['error' => 'Widget owner not found'], Response::HTTP_INTERNAL_SERVER_ERROR);
                }

                // Get fixed task prompt from widget config
                $fixedTaskPromptTopic = $widget->getTaskPromptTopic();

                $isWidgetMode = true;
                $this->logger->info('Widget request authenticated', [
                    'widget_id' => $widgetId,
                    'session_id' => $sessionId,
                    'owner_id' => $user->getId(),
                    'task_prompt' => $fixedTaskPromptTopic,
                ]);
            } else {
                // No user and no widget headers = unauthorized
                return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
            }
        }

        // Check rate limit for authenticated users (not widget mode)
        // We'll check this inside the stream to send a proper SSE error event

        $messageText = $request->query->get('message', '');
        $trackId = $request->query->get('trackId', time());
        $chatId = $request->query->get('chatId', null);
        $includeReasoning = '1' === $request->query->get('reasoning', '0');
        $webSearch = '1' === $request->query->get('webSearch', '0');
        $modelId = $request->query->get('modelId', null);

        $fileIds = $request->query->get('fileIds', ''); // NEW: comma-separated list or single ID

        // Parse fileIds (can be comma-separated string or single ID)
        $fileIdArray = [];
        if (!empty($fileIds)) {
            $fileIdArray = array_map('intval', array_filter(explode(',', $fileIds)));
        }

        if (empty($messageText)) {
            return $this->json(['error' => 'Message is required'], Response::HTTP_BAD_REQUEST);
        }

        if (!$chatId) {
            return $this->json(['error' => 'Chat ID is required'], Response::HTTP_BAD_REQUEST);
        }

        $this->logger->info('StreamController: Received request', [
            'user_id' => $user->getId(),
            'chat_id' => $chatId,
            'has_model_id' => null !== $modelId,
            'model_id' => $modelId,
            'file_ids' => $fileIdArray,
            'file_count' => count($fileIdArray),
        ]);

        // Check rate limit BEFORE starting the stream (not widget mode)
        // Send as SSE event so EventSource can parse it (EventSource cannot read JSON error responses)
        $rateLimitError = null;
        if (!$isWidgetMode) {
            $rateLimitCheck = $this->rateLimitService->checkLimit($user, 'MESSAGES');
            if (!$rateLimitCheck['allowed']) {
                $rateLimitError = [
                    'status' => 'error',
                    'error' => 'Rate limit exceeded',
                    'limit_type' => $rateLimitCheck['limit_type'] ?? 'lifetime',
                    'action_type' => 'MESSAGES',
                    'limit' => $rateLimitCheck['limit'],
                    'used' => $rateLimitCheck['used'],
                    'remaining' => $rateLimitCheck['remaining'],
                    'reset_at' => $rateLimitCheck['reset_at'] ?? null,
                    'user_level' => $user->getUserLevel(),
                    'phone_verified' => $user->isEmailVerified(),
                ];
            }
        }

        // StreamedResponse für SSE
        $response = new StreamedResponse();
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');

        $response->setCallback(function () use ($user, $messageText, $trackId, $chatId, $includeReasoning, $webSearch, $modelId, $fileIdArray, $isWidgetMode, $fixedTaskPromptTopic, $widgetSession, $rateLimitError) {
            // Disable output buffering
            while (ob_get_level()) {
                ob_end_clean();
            }
            ob_implicit_flush(1);
            set_time_limit(0);
            // Stop execution when client disconnects
            ignore_user_abort(false);

            // If rate limit was exceeded, send error as SSE event and return immediately
            // This prevents the user message from being saved when rate limited
            if ($rateLimitError) {
                $this->sendSSE('message', $rateLimitError);

                return;
            }

            try {
                // Load chat
                $chat = $this->em->getRepository(\App\Entity\Chat::class)->find((int) $chatId);
                if (!$chat || $chat->getUserId() !== $user->getId()) {
                    $this->sendSSE('error', ['error' => 'Chat not found or access denied']);

                    return;
                }

                // Rate limit was already checked before stream started
                // (see check before StreamedResponse creation)

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
                $this->em->flush(); // Flush first so message has an ID

                // Attach multiple files if uploaded (NEW: File entities with ManyToMany)
                if (!empty($fileIdArray)) {
                    $fileCount = 0;
                    foreach ($fileIdArray as $fileId) {
                        $file = $this->em->getRepository(File::class)->find($fileId);
                        // Accept files in any status (uploaded, extracted, vectorized)
                        if ($file && $file->getUserId() === $user->getId()) {
                            // Associate file with message using ManyToMany relationship
                            $incomingMessage->addFile($file);
                            ++$fileCount;

                            $this->logger->info('StreamController: File attached to message', [
                                'message_id' => $incomingMessage->getId(),
                                'file_id' => $fileId,
                                'file_path' => $file->getFilePath(),
                                'file_type' => $file->getFileType(),
                                'file_status' => $file->getStatus(),
                            ]);
                        }
                    }

                    if ($fileCount > 0) {
                        // Legacy: set file flag for compatibility
                        $incomingMessage->setFile($fileCount);
                        $this->em->flush();

                        // CRITICAL: Force reload of the entity with files collection!
                        // refresh() doesn't work reliably for collections, so we use clear() + find()
                        $messageId = $incomingMessage->getId();
                        $chatId = $incomingMessage->getChatId();

                        $this->em->clear(); // Detach all entities

                        // Reload message with files
                        $incomingMessage = $this->em->getRepository(Message::class)->find($messageId);

                        if (!$incomingMessage) {
                            $this->logger->error('StreamController: Message not found after refresh!', [
                                'message_id' => $messageId,
                            ]);
                            $this->sendSSE('error', ['message' => 'Internal error: Message lost']);

                            return;
                        }

                        // Reload chat to avoid cascade persist error
                        if ($chatId) {
                            $chat = $this->em->getRepository(\App\Entity\Chat::class)->find($chatId);
                            if ($chat) {
                                $incomingMessage->setChat($chat);
                            }
                        }

                        $this->logger->info('StreamController: Files attached and entity reloaded', [
                            'message_id' => $incomingMessage->getId(),
                            'files_count' => $incomingMessage->getFiles()->count(),
                        ]);

                        // Send preprocessing status to frontend
                        $this->sendSSE('status', [
                            'message' => "Processing $fileCount file(s)...",
                            'stage' => 'preprocessing',
                            'file_count' => $fileCount,
                        ]);
                    }
                }

                // Process with REAL streaming (TEXT only, NO JSON!)
                $responseText = '';
                $chunkCount = 0;

                $processingOptions = [
                    'reasoning' => $includeReasoning,
                    'web_search' => $webSearch, // Use snake_case for consistency with backend
                ];

                // Add model_id if specified (for "Again" functionality)
                if ($modelId) {
                    $processingOptions['model_id'] = (int) $modelId;
                    $this->logger->info('StreamController: Using specified model', [
                        'model_id' => $modelId,
                    ]);
                }

                // Widget Mode: Force fixed task prompt (no sorting)
                if ($isWidgetMode && $fixedTaskPromptTopic) {
                    $processingOptions['fixed_task_prompt'] = $fixedTaskPromptTopic;
                    $this->logger->info('StreamController: Using fixed task prompt for widget', [
                        'task_prompt' => $fixedTaskPromptTopic,
                    ]);
                }

                // Check if selected model supports streaming
                $supportsStreaming = true;
                if ($modelId) {
                    $supportsStreaming = $this->modelConfigService->supportsStreaming((int) $modelId);
                    error_log('🔍 Model supports streaming: '.($supportsStreaming ? 'YES' : 'NO'));
                }

                // Route to streaming or non-streaming handler
                if (!$supportsStreaming) {
                    // Non-streaming models (e.g., o1-preview, o1-mini)
                    $this->handleNonStreamingRequest($incomingMessage, $processingOptions);

                    return; // Exit callback early
                }

                // Regular streaming path
                // Reasoning buffer for wrapping in <think> tags
                $reasoningBuffer = '';
                $hasReasoningStarted = false;

                // ✨ NEW: JSON detection and parsing
                $jsonBuffer = '';
                $isBufferingJson = false;

                $result = $this->messageProcessor->processStream(
                    $incomingMessage,
                    // Stream callback - AI streams TEXT directly or structured data (reasoning)
                    function ($chunk) use (&$responseText, &$chunkCount, &$reasoningBuffer, &$hasReasoningStarted, &$jsonBuffer, &$isBufferingJson) {
                        if (connection_aborted()) {
                            throw new \RuntimeException('Client disconnected');
                        }

                        // Handle structured chunk (reasoning models)
                        if (is_array($chunk)) {
                            $type = $chunk['type'] ?? 'content';
                            $content = $chunk['content'] ?? '';

                            if ('reasoning' === $type) {
                                // Accumulate reasoning chunks
                                if (!$hasReasoningStarted) {
                                    $reasoningBuffer = '<think>';
                                    $hasReasoningStarted = true;
                                }
                                $reasoningBuffer .= $content;
                            } else {
                                // If we have buffered reasoning, close it and send
                                if ($hasReasoningStarted) {
                                    $reasoningBuffer .= '</think>';
                                    $this->sendSSE('data', ['chunk' => $reasoningBuffer]);
                                    $responseText .= $reasoningBuffer;
                                    $reasoningBuffer = '';
                                    $hasReasoningStarted = false;
                                }

                                // Regular content
                                $responseText .= $content;
                                if (!empty($content)) {
                                    $this->sendSSE('data', ['chunk' => $content]);
                                }
                            }
                        } else {
                            // Close any open reasoning buffer
                            if ($hasReasoningStarted) {
                                $reasoningBuffer .= '</think>';
                                $this->sendSSE('data', ['chunk' => $reasoningBuffer]);
                                $responseText .= $reasoningBuffer;
                                $reasoningBuffer = '';
                                $hasReasoningStarted = false;
                            }

                            // ✨ JSON detection and buffering during streaming
                            // Detect and buffer JSON responses
                            if (is_string($chunk) && !empty(trim($chunk))) {
                                // Start buffering if this is the FIRST chunk and it starts with {
                                if (!$isBufferingJson && 0 === $chunkCount && str_starts_with(trim($chunk), '{')) {
                                    $isBufferingJson = true;
                                    $jsonBuffer = $chunk;
                                    ++$chunkCount;

                                    return; // Don't send yet, buffer it
                                }
                            }

                            if ($isBufferingJson) {
                                $jsonBuffer .= $chunk;

                                // Check if JSON is complete (has closing brace)
                                if (str_contains($jsonBuffer, '}')) {
                                    // Try to find the complete JSON object
                                    $trimmed = trim($jsonBuffer);

                                    // Find last closing brace position
                                    $lastBrace = strrpos($trimmed, '}');
                                    if (false !== $lastBrace) {
                                        $potentialJson = substr($trimmed, 0, $lastBrace + 1);

                                        // ✨ FIX: AI sometimes generates invalid JSON with "BFILE": \n} instead of "BFILE": 0
                                        $potentialJson = preg_replace('/"BFILE":\s*\n/', '"BFILE": 0'."\n", $potentialJson);
                                        $potentialJson = preg_replace('/"BFILE":\s*\r\n/', '"BFILE": 0'."\r\n", $potentialJson);
                                        $potentialJson = preg_replace('/"BFILE":\s*}/', '"BFILE": 0}', $potentialJson);

                                        try {
                                            $jsonData = json_decode($potentialJson, true, 512, JSON_THROW_ON_ERROR);

                                            // Extract BTEXT and send ONLY that
                                            if (isset($jsonData['BTEXT'])) {
                                                $extractedText = $jsonData['BTEXT'];
                                                $responseText .= $extractedText;
                                                $this->sendSSE('data', ['chunk' => $extractedText]);

                                                $isBufferingJson = false;
                                                $jsonBuffer = '';

                                                return;
                                            }
                                        } catch (\JsonException $e) {
                                            // JSON not valid yet, keep buffering
                                            return;
                                        }
                                    }
                                }

                                return; // Keep buffering
                            }

                            // Normal text chunk (not JSON)
                            $responseText .= $chunk;

                            // Log if we detect <think> tags
                            if (false !== strpos($chunk, '<think>') || false !== strpos($chunk, '</think>')) {
                                error_log('🧠 StreamController: <think> tag detected in chunk: '.substr($chunk, 0, 100));
                            }

                            if (!empty($chunk)) {
                                $this->sendSSE('data', ['chunk' => $chunk]);
                            }
                        }

                        if (0 === $chunkCount) {
                            error_log('🔵 StreamController: Started streaming');
                        }
                        ++$chunkCount;
                    },
                    // Status callback
                    function ($statusUpdate) {
                        if ('complete' === $statusUpdate['status']) {
                            return;
                        }

                        $this->sendSSE($statusUpdate['status'], [
                            'message' => $statusUpdate['message'],
                            'metadata' => $statusUpdate['metadata'] ?? [],
                            'timestamp' => $statusUpdate['timestamp'],
                        ]);
                    },
                    $processingOptions
                );

                // Close any open reasoning buffer at the end
                if ($hasReasoningStarted) {
                    $reasoningBuffer .= '</think>';
                    $this->sendSSE('data', ['chunk' => $reasoningBuffer]);
                    $responseText .= $reasoningBuffer;
                }

                if (!$result['success']) {
                    // Build user-friendly error message as AI response
                    $isDev = 'dev' === $this->getParameter('kernel.environment');

                    $errorMessage = '## ⚠️ '.$result['error']."\n\n";

                    // Add installation instructions ONLY in dev mode
                    if ($isDev && isset($result['context'])) {
                        $context = $result['context'];

                        // If a specific model was requested, show it prominently
                        if (isset($context['requested_model']) && isset($context['install_command'])) {
                            $errorMessage .= "### 💡 Install the Model You Selected\n\n";
                            $errorMessage .= "```bash\n".$context['install_command']."\n```\n\n";
                        }

                        // Show alternative models if available
                        if (isset($context['suggested_models'])) {
                            $errorMessage .= "### 📦 Or Try These Alternatives\n\n";

                            if (isset($context['suggested_models']['quick'])) {
                                $errorMessage .= "**Quick & Light:**\n";
                                foreach ($context['suggested_models']['quick'] as $model) {
                                    $errorMessage .= "- `{$model}`\n";
                                }
                                $errorMessage .= "\n";
                            }

                            if (isset($context['suggested_models']['medium'])) {
                                $errorMessage .= "**Medium (Better Quality):**\n";
                                foreach ($context['suggested_models']['medium'] as $model) {
                                    $errorMessage .= "- `{$model}`\n";
                                }
                                $errorMessage .= "\n";
                            }

                            if (isset($context['suggested_models']['large'])) {
                                $errorMessage .= "**Large (Best Quality):**\n";
                                foreach ($context['suggested_models']['large'] as $model) {
                                    $errorMessage .= "- `{$model}`\n";
                                }
                                $errorMessage .= "\n";
                            }
                        }

                        $errorMessage .= '*After downloading, refresh the page and try again.*';
                    } elseif (!$isDev) {
                        // Production: Generic message without technical details
                        $errorMessage .= '*Please contact your system administrator or try selecting a different AI model.*';
                    }

                    // Stream the error message as data chunks (like normal AI response)
                    $this->sendSSE('data', ['chunk' => $errorMessage]);

                    // Save error message to database
                    $outgoingMessage = new Message();
                    $outgoingMessage->setUserId($user->getId());
                    $outgoingMessage->setChat($chat);
                    $outgoingMessage->setTrackingId($trackId);
                    $outgoingMessage->setProviderIndex($incomingMessage->getProviderIndex()); // Use same channel as incoming
                    $outgoingMessage->setUnixTimestamp(time());
                    $outgoingMessage->setDateTime(date('YmdHis'));
                    $outgoingMessage->setMessageType('WEB');
                    $outgoingMessage->setFile(0);
                    $outgoingMessage->setTopic('ERROR');
                    $outgoingMessage->setLanguage('en');
                    $outgoingMessage->setText($errorMessage);
                    $outgoingMessage->setDirection('OUT');
                    $outgoingMessage->setStatus('complete');

                    $this->em->persist($outgoingMessage);
                    $this->em->flush(); // Flush to get message ID for metadata

                    // Store error details in metadata
                    $outgoingMessage->setMeta('ai_provider', $result['provider'] ?? 'system');
                    $outgoingMessage->setMeta('ai_model', 'error');
                    $outgoingMessage->setMeta('error_type', $result['error'] ?? 'unknown');

                    // Update incoming message
                    $incomingMessage->setTopic('ERROR');
                    $incomingMessage->setStatus('error');

                    $chat->updateTimestamp();
                    $this->em->flush();

                    // Send complete event
                    $this->sendSSE('complete', [
                        'messageId' => $outgoingMessage->getId(),
                        'trackId' => $trackId,
                        'provider' => $result['provider'] ?? 'system',
                        'model' => 'error',
                        'topic' => 'ERROR',
                        'language' => 'en',
                    ]);

                    return; // Exit early
                }

                $classification = $result['classification'];
                $response = $result['response'];

                error_log('🔵 StreamController: Streaming complete, '.$chunkCount.' chunks');
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
                        'type' => $fileType,
                    ]);
                }

                if (isset($response['metadata']['links'])) {
                    $this->sendSSE('links', [
                        'links' => $response['metadata']['links'],
                    ]);
                    $this->logger->info('StreamController: Handler provided links');
                }

                // Create outgoing message
                $outgoingMessage = new Message();
                $outgoingMessage->setUserId($user->getId());
                $outgoingMessage->setChat($chat);
                $outgoingMessage->setTrackingId($trackId);
                $outgoingMessage->setProviderIndex($incomingMessage->getProviderIndex()); // Use same channel as incoming
                $outgoingMessage->setUnixTimestamp(time());
                $outgoingMessage->setDateTime(date('YmdHis'));
                $outgoingMessage->setMessageType('WEB');
                $outgoingMessage->setFile($hasFile);
                $outgoingMessage->setFilePath($filePath);
                $outgoingMessage->setFileType($fileType);
                $outgoingMessage->setTopic($classification['topic']);
                $outgoingMessage->setLanguage($classification['language']);

                // ✨ Parse JSON response if AI responded in JSON format (fallback for non-streamed parsing)
                $finalText = $responseText;
                $generatedFile = null;

                // NEW: Check for file generation format first (for OfficeM maker)
                // Extract JSON from markdown code blocks if present (```json ... ```)
                $jsonContent = $responseText;
                if (preg_match('/```(?:json)?\s*\n(.*?)\n```/s', $responseText, $matches)) {
                    $jsonContent = trim($matches[1]);
                    $this->logger->info('StreamController: Extracted JSON from markdown code block');
                }

                if (str_starts_with(trim($jsonContent), '{')) {
                    try {
                        $jsonData = json_decode($jsonContent, true, 512, JSON_THROW_ON_ERROR);

                        // Check for NEW file generation format
                        if (isset($jsonData['BFILEPATH']) && isset($jsonData['BFILETEXT'])) {
                            $this->logger->info('StreamController: Detected AI file generation', [
                                'filename' => $jsonData['BFILEPATH'],
                            ]);

                            // Send generating status BEFORE creating the file
                            $this->sendSSE('generating', [
                                'message' => 'Datei wird generiert...',
                                'metadata' => [
                                    'customMessage' => 'Erstelle Datei: '.$jsonData['BFILEPATH'],
                                ],
                            ]);

                            // Store the file
                            $fileData = [
                                'filename' => $jsonData['BFILEPATH'],
                                'content' => $jsonData['BFILETEXT'],
                                'extension' => strtolower(pathinfo($jsonData['BFILEPATH'], PATHINFO_EXTENSION)),
                            ];

                            $generatedFile = $this->storeGeneratedFileInStream($fileData, $incomingMessage);

                            if ($generatedFile) {
                                $finalText = "__FILE_GENERATED__:{$jsonData['BFILEPATH']}";
                                $this->logger->info('StreamController: File generation successful', [
                                    'file_id' => $generatedFile->getId(),
                                    'filename' => $generatedFile->getFileName(),
                                ]);
                            } else {
                                $finalText = '__FILE_GENERATION_FAILED__';
                                $this->logger->error('StreamController: File generation failed');
                            }
                        }
                        // Legacy BTEXT format
                        elseif (isset($jsonData['BTEXT'])) {
                            $finalText = $jsonData['BTEXT'];
                        }
                    } catch (\JsonException $e) {
                        // Not valid JSON or extraction failed
                        // ✨ FIX: AI sometimes generates invalid JSON with "BFILE": \n} instead of "BFILE": 0
                        $cleanedJson = preg_replace('/"BFILE":\s*\n/', '"BFILE": 0'."\n", $jsonContent);
                        $cleanedJson = preg_replace('/"BFILE":\s*\r\n/', '"BFILE": 0'."\r\n", $cleanedJson);
                        $cleanedJson = preg_replace('/"BFILE":\s*}/', '"BFILE": 0}', $cleanedJson);

                        try {
                            $jsonData = json_decode($cleanedJson, true, 512, JSON_THROW_ON_ERROR);

                            // Extract BTEXT as main content
                            if (isset($jsonData['BTEXT'])) {
                                $finalText = $jsonData['BTEXT'];
                            }
                        } catch (\JsonException $e2) {
                            // Not valid JSON or extraction failed, use content as-is
                            $this->logger->warning('StreamController: Failed to parse JSON', [
                                'error' => $e2->getMessage(),
                                'content_preview' => substr($jsonContent, 0, 200),
                            ]);
                        }
                    }
                }

                $outgoingMessage->setText($finalText); // Pure TEXT, not JSON
                $outgoingMessage->setDirection('OUT');
                $outgoingMessage->setStatus('complete');

                $this->em->persist($outgoingMessage);
                $this->em->flush(); // Flush to get message ID for metadata

                // Attach generated file to message if present
                if ($generatedFile) {
                    $outgoingMessage->addFile($generatedFile);
                    $this->em->flush();
                }

                // DEBUG: Log what we're about to save
                error_log('🔍 CHAT MODEL: '.($response['metadata']['provider'] ?? 'unknown').' / '.($response['metadata']['model'] ?? 'unknown'));
                error_log('🔍 SORTING MODEL: '.($classification['sorting_provider'] ?? 'null').' / '.($classification['sorting_model_name'] ?? 'null').' (ID: '.($classification['sorting_model_id'] ?? 'null').')');

                $this->logger->info('🔍 StreamController: Saving model metadata', [
                    'chat_provider' => $response['metadata']['provider'] ?? 'unknown',
                    'chat_model' => $response['metadata']['model'] ?? 'unknown',
                    'sorting_provider' => $classification['sorting_provider'] ?? null,
                    'sorting_model' => $classification['sorting_model_name'] ?? null,
                    'sorting_model_id' => $classification['sorting_model_id'] ?? null,
                ]);

                // Store CHAT model information in MessageMeta
                $outgoingMessage->setMeta('ai_chat_provider', $response['metadata']['provider'] ?? 'unknown');
                $outgoingMessage->setMeta('ai_chat_model', $response['metadata']['model'] ?? 'unknown');

                // Store CHAT model_id if available (from user selection or resolved by ChatHandler)
                if (!empty($modelId)) {
                    $outgoingMessage->setMeta('ai_chat_model_id', (string) $modelId);
                    $this->logger->info('StreamController: Storing chat model ID from user selection', [
                        'model_id' => $modelId,
                    ]);
                } elseif (!empty($response['metadata']['model_id'])) {
                    $outgoingMessage->setMeta('ai_chat_model_id', (string) $response['metadata']['model_id']);
                    $this->logger->info('StreamController: Storing chat model ID from response', [
                        'model_id' => $response['metadata']['model_id'],
                    ]);
                }

                if (!empty($response['metadata']['usage'])) {
                    $outgoingMessage->setMeta('ai_chat_usage', json_encode($response['metadata']['usage']));
                }

                if (!empty($response['metadata']['media_prompt'])) {
                    $outgoingMessage->setMeta('media_prompt', $response['metadata']['media_prompt']);
                }
                if (!empty($response['metadata']['media_type'])) {
                    $outgoingMessage->setMeta('media_type', $response['metadata']['media_type']);
                }

                // Store SORTING model information in MessageMeta (from classification)
                if (!empty($classification['sorting_provider'])) {
                    $outgoingMessage->setMeta('ai_sorting_provider', $classification['sorting_provider']);
                }
                if (!empty($classification['sorting_model_name'])) {
                    $outgoingMessage->setMeta('ai_sorting_model', $classification['sorting_model_name']);
                }
                if (!empty($classification['sorting_model_id'])) {
                    $outgoingMessage->setMeta('ai_sorting_model_id', (string) $classification['sorting_model_id']);
                }

                // Store Web Search metadata if web search was used
                if ($webSearch) {
                    $incomingMessage->setMeta('web_search_enabled', 'true');
                    $this->logger->info('StreamController: Web search was enabled for this message');
                }

                // Store if search results were found (will be processed below)
                $hasSearchResults = isset($result['search_results']) && !empty($result['search_results']['results']);
                if ($hasSearchResults) {
                    $searchQuery = $result['search_results']['query'] ?? '';
                    $searchCount = count($result['search_results']['results']);

                    $incomingMessage->setMeta('web_search_query', $searchQuery);
                    $incomingMessage->setMeta('web_search_results_count', (string) $searchCount);
                    $outgoingMessage->setMeta('web_search_query', $searchQuery);
                    $outgoingMessage->setMeta('web_search_results_count', (string) $searchCount);

                    $this->logger->info('StreamController: Stored search results metadata', [
                        'query' => $searchQuery,
                        'results_count' => $searchCount,
                    ]);
                }

                // Update incoming message
                $incomingMessage->setTopic($classification['topic']);
                $incomingMessage->setLanguage($classification['language']);
                $incomingMessage->setStatus('complete');

                $chat->updateTimestamp();

                $this->em->flush();

                // Record usage for rate limiting and statistics
                $this->rateLimitService->recordUsage($user, 'MESSAGES', [
                    'provider' => $response['metadata']['provider'] ?? 'unknown',
                    'model' => $response['metadata']['model'] ?? 'unknown',
                    'tokens' => $response['metadata']['usage']['total_tokens'] ?? 0,
                    'latency' => $response['metadata']['latency'] ?? 0,
                    'chat_id' => $chatId,
                    'source' => $isWidgetMode ? 'WIDGET' : 'WEB',
                ]);

                // Get search results if available
                $searchResults = null;
                if (isset($result['search_results']) && !empty($result['search_results']['results'])) {
                    $searchResults = array_map(function ($result) {
                        return [
                            'title' => $result['title'] ?? '',
                            'url' => $result['url'] ?? '',
                            'description' => $result['description'] ?? '',
                            'published' => $result['age'] ?? null,
                            'source' => $result['profile']['name'] ?? null,
                            'thumbnail' => $result['thumbnail'] ?? null,
                        ];
                    }, $result['search_results']['results']);

                    $this->logger->info('StreamController: Including search results', [
                        'results_count' => count($searchResults),
                        'query' => $result['search_results']['query'],
                    ]);
                }

                // Send complete event (WITHOUT againData - frontend handles this)
                $completeData = [
                    'messageId' => $outgoingMessage->getId(),
                    'trackId' => $trackId,
                    'provider' => $response['metadata']['provider'] ?? 'test',
                    'model' => $response['metadata']['model'] ?? 'unknown',
                    'topic' => $classification['topic'],
                    'language' => $classification['language'],
                    'searchResults' => $searchResults, // Include search results
                ];

                // Include generated file info if present
                if ($generatedFile) {
                    $completeData['generatedFile'] = [
                        'id' => $generatedFile->getId(),
                        'filename' => $generatedFile->getFileName(),
                        'path' => $generatedFile->getFilePath(),
                        'size' => $generatedFile->getFileSize(),
                        'type' => $generatedFile->getFileType(),
                        'mime' => $generatedFile->getFileMime(),
                    ];

                    $this->logger->info('StreamController: Including generated file in complete event', [
                        'file_id' => $generatedFile->getId(),
                        'filename' => $generatedFile->getFileName(),
                    ]);
                }

                $this->sendSSE('complete', $completeData);

                usleep(100000);

                $this->logger->info('Streamed message processed', [
                    'user_id' => $user->getId(),
                    'message_id' => $outgoingMessage->getId(),
                    'topic' => $classification['topic'],
                ]);

                // Widget Mode: Increment session message count
                if ($isWidgetMode && $widgetSession) {
                    $this->widgetSessionService->incrementMessageCount($widgetSession);
                    $this->logger->info('Widget session message count incremented', [
                        'session_id' => $widgetSession->getSessionId(),
                        'message_count' => $widgetSession->getMessageCount(),
                    ]);
                }
            } catch (\App\AI\Exception\ProviderException $e) {
                $this->logger->error('AI Provider failed', [
                    'user_id' => $user->getId(),
                    'error' => $e->getMessage(),
                    'provider' => $e->getProviderName(),
                    'context' => $e->getContext(),
                ]);

                $errorData = [
                    'error' => $e->getMessage(),
                    'provider' => $e->getProviderName(),
                ];

                // Add installation instructions if available
                if ($context = $e->getContext()) {
                    $errorData['install_command'] = $context['install_command'] ?? null;
                    $errorData['suggested_models'] = $context['suggested_models'] ?? null;
                }

                $this->sendSSE('error', $errorData);
            } catch (\Exception $e) {
                // Don't send error if client disconnected intentionally
                if ('Client disconnected' === $e->getMessage()) {
                    $this->logger->info('Stream stopped - client disconnected', [
                        'user_id' => $user->getId(),
                    ]);

                    return; // Silently stop
                }

                $this->logger->error('Streaming failed', [
                    'user_id' => $user->getId(),
                    'error' => $e->getMessage(),
                ]);

                $this->sendSSE('error', [
                    'error' => 'Failed to process message: '.$e->getMessage(),
                ]);
            }
        });

        return $response;
    }

    /**
     * Handle non-streaming requests for models that don't support streaming (e.g., o1-preview).
     */
    private function handleNonStreamingRequest(Message $message, array $options): void
    {
        try {
            // Send processing status
            $this->sendSSE('status', ['message' => 'Processing with non-streaming model...']);

            // Process message without streaming
            $result = $this->messageProcessor->process($message, $options);

            if (!$result['success']) {
                $this->sendSSE('error', ['error' => $result['error']]);

                return;
            }

            // Get response content
            $content = $result['content'] ?? '';
            $metadata = $result['metadata'] ?? [];

            // Extract reasoning if present (for o1 models)
            $reasoning = null;
            if (isset($metadata['reasoning'])) {
                $reasoning = $metadata['reasoning'];
                unset($metadata['reasoning']);
            }

            // Send reasoning first if available
            if ($reasoning) {
                $this->sendSSE('reasoning_complete', ['reasoning' => $reasoning]);
            }

            // Send content in one chunk (simulating streaming)
            $this->sendSSE('data', ['chunk' => $content]);

            // Send complete event
            $this->sendSSE('complete', [
                'messageId' => $message->getId(),
                'provider' => $metadata['provider'] ?? 'unknown',
                'model' => $metadata['model'] ?? 'unknown',
                'trackId' => $_GET['trackId'] ?? time(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Non-streaming processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendSSE('error', ['error' => 'Failed to process: '.$e->getMessage()]);
        }
    }

    private function sendSSE(string $status, array $data): void
    {
        if (connection_aborted()) {
            error_log('🔴 StreamController: Connection aborted');

            return;
        }

        // Sanitize all string values in data to ensure valid UTF-8
        $sanitizedData = $this->sanitizeUtf8($data);

        $event = [
            'status' => $status,
            ...$sanitizedData,
        ];

        echo 'data: '.json_encode($event, JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    /**
     * Recursively sanitize UTF-8 in arrays to prevent JSON encoding errors.
     */
    private function sanitizeUtf8($value)
    {
        if (is_array($value)) {
            return array_map([$this, 'sanitizeUtf8'], $value);
        }

        if (is_string($value)) {
            // Remove invalid UTF-8 characters
            return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        return $value;
    }

    /**
     * Store AI-generated file in the file system and create File entity
     * Same logic as ChatHandler::storeGeneratedFile.
     */
    private function storeGeneratedFileInStream(array $fileData, Message $message): ?File
    {
        $userId = $message->getUserId();
        $filename = $fileData['filename'];
        $content = $fileData['content'];
        $extension = $fileData['extension'];

        try {
            // Generate storage path similar to FileStorageService
            $year = date('Y');
            $month = date('m');
            $timestamp = time();

            // Sanitize filename
            $sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
            $sanitized = preg_replace('/_+/', '_', $sanitized);

            // Add timestamp to prevent collisions
            $basename = pathinfo($sanitized, PATHINFO_FILENAME);
            $finalFilename = $basename.'_'.$timestamp.'.'.$extension;

            // Create relative path
            $relativePath = sprintf('%d/%s/%s/%s', $userId, $year, $month, $finalFilename);
            $absolutePath = $this->uploadDir.'/'.$relativePath;

            // Create directory if not exists
            $dir = dirname($absolutePath);
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true)) {
                    $this->logger->error('StreamController: Failed to create directory', ['dir' => $dir]);

                    return null;
                }
            }

            // Write file content
            if (!file_put_contents($absolutePath, $content)) {
                $this->logger->error('StreamController: Failed to write file', ['path' => $absolutePath]);

                return null;
            }

            // Detect MIME type
            $mimeType = $this->getMimeTypeForExtension($extension);

            // Create File entity
            $file = new File();
            $file->setUserId($userId);
            $file->setFilePath($relativePath);
            $file->setFileType($extension);
            $file->setFileName($filename);
            $file->setFileSize(strlen($content));
            $file->setFileMime($mimeType);
            $file->setFileText($content); // Store content as text for searchability
            $file->setStatus('generated');

            $this->em->persist($file);
            $this->em->flush();

            $this->logger->info('StreamController: File generated and stored successfully', [
                'file_id' => $file->getId(),
                'filename' => $filename,
                'path' => $relativePath,
                'size' => $file->getFileSize(),
            ]);

            return $file;
        } catch (\Throwable $e) {
            $this->logger->error('StreamController: Failed to store generated file', [
                'filename' => $filename,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Get MIME type for file extension.
     */
    private function getMimeTypeForExtension(string $extension): string
    {
        return match (strtolower($extension)) {
            'csv' => 'text/csv',
            'txt' => 'text/plain',
            'md' => 'text/markdown',
            'html' => 'text/html',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }

    /**
     * Save cancelled message - persist cancelled streaming message to database.
     */
    #[Route('/save-cancelled', name: 'save_cancelled', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/messages/save-cancelled',
        summary: 'Save cancelled streaming message',
        description: 'Save a cancelled streaming message to the database',
        security: [['Bearer' => []]],
        tags: ['Messages']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'trackId', type: 'integer', example: 1234567890),
                new OA\Property(property: 'chatId', type: 'integer', example: 123),
                new OA\Property(property: 'content', type: 'string', example: 'Partial response\n\n_Cancelled by user_'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Message saved successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'messageId', type: 'integer', example: 456),
            ]
        )
    )]
    public function saveCancelled(Request $request, #[CurrentUser] ?User $user): Response
    {
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $trackId = $data['trackId'] ?? null;
        $chatId = $data['chatId'] ?? null;
        $content = $data['content'] ?? '';
        $provider = $data['provider'] ?? null;
        $model = $data['model'] ?? null;
        $topic = $data['topic'] ?? null;

        if (!$trackId || !$chatId) {
            return $this->json(['error' => 'Missing trackId or chatId'], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Load chat
            $chat = $this->em->getRepository(\App\Entity\Chat::class)->find((int) $chatId);
            if (!$chat || $chat->getUserId() !== $user->getId()) {
                return $this->json(['error' => 'Chat not found or access denied'], Response::HTTP_FORBIDDEN);
            }

            // Find the incoming message by trackId
            $incomingMessage = $this->em->getRepository(Message::class)->findOneBy([
                'userId' => $user->getId(),
                'trackingId' => $trackId,
                'direction' => 'IN',
            ]);

            if (!$incomingMessage) {
                $this->logger->warning('Incoming message not found for cancelled response', [
                    'user_id' => $user->getId(),
                    'track_id' => $trackId,
                ]);

                return $this->json(['error' => 'Incoming message not found'], Response::HTTP_NOT_FOUND);
            }

            // Create outgoing message for the cancelled response
            $outgoingMessage = new Message();
            $outgoingMessage->setUserId($user->getId());
            $outgoingMessage->setChat($chat);
            $outgoingMessage->setTrackingId($trackId);
            $outgoingMessage->setProviderIndex($incomingMessage->getProviderIndex());
            $outgoingMessage->setUnixTimestamp(time());
            $outgoingMessage->setDateTime(date('YmdHis'));
            $outgoingMessage->setMessageType($incomingMessage->getMessageType());
            $outgoingMessage->setFile(0);
            $outgoingMessage->setTopic($incomingMessage->getTopic());
            $outgoingMessage->setLanguage($incomingMessage->getLanguage());
            $outgoingMessage->setText($content);
            $outgoingMessage->setDirection('OUT');
            $outgoingMessage->setStatus('cancelled');

            $this->em->persist($outgoingMessage);
            $this->em->flush();

            // Store metadata indicating it was cancelled
            $outgoingMessage->setMeta('cancelled', 'true');
            $outgoingMessage->setMeta('cancelled_at', date('Y-m-d H:i:s'));

            // Use metadata from request (set during streaming) or fall back to incoming message
            $chatProvider = $provider ?? $incomingMessage->getMeta('ai_chat_provider');
            $chatModel = $model ?? $incomingMessage->getMeta('ai_chat_model');
            $sortingProvider = $incomingMessage->getMeta('ai_sorting_provider');
            $sortingModel = $incomingMessage->getMeta('ai_sorting_model');

            // Store model metadata if available
            if ($chatProvider) {
                $outgoingMessage->setMeta('ai_chat_provider', $chatProvider);
            }
            if ($chatModel) {
                $outgoingMessage->setMeta('ai_chat_model', $chatModel);
            }
            if ($sortingProvider) {
                $outgoingMessage->setMeta('ai_sorting_provider', $sortingProvider);
            }
            if ($sortingModel) {
                $outgoingMessage->setMeta('ai_sorting_model', $sortingModel);
            }

            // Update topic if provided from frontend
            if ($topic) {
                $outgoingMessage->setTopic($topic);
            }

            $this->em->flush();

            // Update incoming message status
            $incomingMessage->setStatus('cancelled');
            $this->em->flush();

            $this->logger->info('Cancelled message saved', [
                'user_id' => $user->getId(),
                'track_id' => $trackId,
                'message_id' => $outgoingMessage->getId(),
            ]);

            return $this->json([
                'success' => true,
                'messageId' => $outgoingMessage->getId(),
                'topic' => $outgoingMessage->getTopic(),
                'provider' => $chatProvider,
                'model' => $chatModel,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to save cancelled message', [
                'user_id' => $user->getId(),
                'track_id' => $trackId,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Failed to save message',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Stop streaming endpoint - allows frontend to explicitly stop streaming.
     */
    #[Route('/stop-stream', name: 'stop_stream', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/messages/stop-stream',
        summary: 'Stop streaming AI response',
        description: 'Explicitly stop an ongoing streaming response',
        security: [['Bearer' => []]],
        tags: ['Messages']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'trackId', type: 'integer', example: 1234567890),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Stream stop acknowledged',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Stream stop requested'),
            ]
        )
    )]
    public function stopStream(Request $request, #[CurrentUser] ?User $user): Response
    {
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $trackId = $data['trackId'] ?? null;

        $this->logger->info('Stop stream requested', [
            'user_id' => $user->getId(),
            'track_id' => $trackId,
        ]);

        // Note: The actual stopping happens via connection_aborted() checks in the streaming loop
        // This endpoint serves as a signal to the frontend that the stop was acknowledged
        // The EventSource.close() on the frontend side will trigger connection_aborted() on the backend

        return $this->json([
            'success' => true,
            'message' => 'Stream stop requested',
        ]);
    }
}
