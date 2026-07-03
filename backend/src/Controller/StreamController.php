<?php

namespace App\Controller;

use App\AI\Service\AiFacade;
use App\Entity\Chat;
use App\Entity\File;
use App\Entity\Message;
use App\Entity\Prompt;
use App\Entity\User;
use App\Message\ExtractMemoriesCommand;
use App\Service\Exception\StreamCancelledException;
use App\Service\File\DocumentGeneratorService;
use App\Service\File\UserUploadPathBuilder;
use App\Service\GuestSessionService;
use App\Service\Media\MediaCancellationStore;
use App\Service\Media\MediaJobMessageSync;
use App\Service\Media\MediaJobService;
use App\Service\MemoryExtractionDispatcher;
use App\Service\Message\MessageForwardingService;
use App\Service\Message\MessageProcessor;
use App\Service\ModelConfigService;
use App\Service\PerfTimer;
use App\Service\PromptService;
use App\Service\RateLimitService;
use App\Service\TtsTextSanitizer;
use App\Service\WidgetService;
use App\Service\WidgetSessionService;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/messages', name: 'api_messages_')]
#[OA\Tag(name: 'Messages')]
class StreamController extends AbstractController
{
    /**
     * Server-side cap for a quoted-reference excerpt ("Mention in chat").
     *
     * The quote travels as a query parameter on the SSE (GET) stream URL, so an
     * over-long excerpt could exceed reverse-proxy request limits (e.g. Nginx's
     * 8 KB default). Kept in sync with the frontend cap (MAX_QUOTE_LENGTH in
     * useMessageQuoting.ts); 1000 chars is generous for a reference point and
     * stays URL-safe even worst-case encoded.
     */
    private const MAX_QUOTED_TEXT_LENGTH = 1000;

    public function __construct(
        private EntityManagerInterface $em,
        private AiFacade $aiFacade,
        private MessageProcessor $messageProcessor,
        private LoggerInterface $logger,
        private ModelConfigService $modelConfigService,
        private WidgetService $widgetService,
        private WidgetSessionService $widgetSessionService,
        private GuestSessionService $guestSessionService,
        private RateLimitService $rateLimitService,
        private string $uploadDir,
        private UserUploadPathBuilder $userUploadPathBuilder,
        private PromptService $promptService,
        private MessageForwardingService $messageForwardingService,
        private MemoryExtractionDispatcher $memoryExtractionDispatcher,
        private DocumentGeneratorService $documentGenerator,
        private MediaCancellationStore $cancellationStore,
        private MediaJobService $mediaJobService,
        private MediaJobMessageSync $mediaJobMessageSync,
        #[Autowire(env: 'default::bool:COST_BUDGET_GATE_ENABLED')]
        private bool $costBudgetGateEnabled = false,
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
    #[OA\Parameter(
        name: 'voiceReply',
        in: 'query',
        required: false,
        description: 'Generate audio (MP3) voice reply in addition to text (1 or 0)',
        schema: new OA\Schema(type: 'string', enum: ['0', '1'], example: '0')
    )]
    #[OA\Parameter(
        name: 'isAgain',
        in: 'query',
        required: false,
        description: 'Whether this is an "Again" request (retry with specific model, skip classification). Required when modelId is set to distinguish model override from retry.',
        schema: new OA\Schema(type: 'string', enum: ['0', '1'], example: '0')
    )]
    #[OA\Parameter(
        name: 'promptTopic',
        in: 'query',
        required: false,
        description: 'Topic of a custom prompt to use (e.g., "customersupport", "legal-review"). Overrides auto-classification. Use GET /api/v1/prompts to list available topics.',
        schema: new OA\Schema(type: 'string', example: 'customersupport')
    )]
    #[OA\Parameter(
        name: 'promptId',
        in: 'query',
        required: false,
        description: 'ID of a specific prompt to use. Takes precedence over promptTopic. The prompt must belong to the current user or be a system prompt.',
        schema: new OA\Schema(type: 'integer', example: 42)
    )]
    #[OA\Parameter(
        name: 'ragGroupKey',
        in: 'query',
        required: false,
        description: 'Knowledge-base folder (file group key) to scope this message\'s RAG retrieval to. Use GET /api/v1/files/groups to list available folders.',
        schema: new OA\Schema(type: 'string', example: 'project:helios')
    )]
    #[OA\Parameter(
        name: 'quotedText',
        in: 'query',
        required: false,
        description: 'Text the user selected from an earlier message to use as the primary reference point for this request ("Mention in chat"). Injected into the AI system prompt as a quoted-reference context block.',
        schema: new OA\Schema(type: 'string', example: 'The deployment runs on FrankenPHP.')
    )]
    #[OA\Parameter(
        name: 'quotedMessageId',
        in: 'query',
        required: false,
        description: 'Optional backend id of the message the quoted text was taken from. Must belong to the same chat and user.',
        schema: new OA\Schema(type: 'integer', example: 456)
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
        $isGuestMode = false;
        $widget = null;
        $widgetSession = null;
        $guestSession = null;
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

                // Validate test mode: only allow if authenticated user is widget owner
                // This prevents malicious users from marking sessions as test to avoid statistics
                $isValidatedTestMode = $this->isValidatedTestMode($request, $widget->getOwnerId());

                // Get or create widget session
                $widgetSession = $this->widgetSessionService->getOrCreateSession($widgetId, $sessionId, $isValidatedTestMode);

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
                // Guest Mode: Check for guest session query parameter
                $guestSessionId = $request->query->get('guestSession');
                if ($guestSessionId) {
                    $guestSession = $this->guestSessionService->getSession($guestSessionId);
                    if ($guestSession && !$guestSession->isExpired()) {
                        if (!$this->guestSessionService->checkLimit($guestSession)) {
                            $response = new StreamedResponse();
                            $response->headers->set('Content-Type', 'text/event-stream');
                            $response->headers->set('Cache-Control', 'no-cache');
                            $response->headers->set('X-Accel-Buffering', 'no');
                            $response->setCallback(function () {
                                $this->sendSSE('guest_limit_reached', [
                                    'message' => 'Guest message limit reached',
                                    'action' => 'signup_required',
                                ]);
                            });

                            return $response;
                        }

                        $user = $this->guestSessionService->getProcessingUser();
                        if (!$user) {
                            return $this->json(['error' => 'Guest mode unavailable'], Response::HTTP_INTERNAL_SERVER_ERROR);
                        }

                        $isGuestMode = true;
                        $this->logger->info('Guest request authenticated', [
                            'session_id' => substr($guestSessionId, 0, 12).'...',
                            'remaining' => $this->guestSessionService->getRemainingMessages($guestSession),
                        ]);
                    } else {
                        return $this->json(['error' => 'Guest session not found or expired'], Response::HTTP_UNAUTHORIZED);
                    }
                } else {
                    return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
                }
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

        $voiceReply = '1' === $request->query->get('voiceReply', '0');
        $isAgain = '1' === $request->query->get('isAgain', '0');
        $fileIds = $request->query->get('fileIds', ''); // NEW: comma-separated list or single ID
        $promptTopic = $request->query->get('promptTopic');
        $promptId = $request->query->get('promptId');
        // Typed accessor: `get()` would hand back an array for `ragGroupKey[]=…`,
        // which then flows into a string-typed processing option and 500s
        // downstream. `getString()` rejects non-scalar input with a clean 400,
        // and we normalize the empty string to null so "no folder selected"
        // behaves the same as an absent parameter.
        $ragGroupKey = $request->query->getString('ragGroupKey') ?: null;
        // Quoted reference the user selected from an earlier message ("Mention
        // in chat"). `getString()` rejects array input with a clean 400. We trim
        // and cap the excerpt server-side so a crafted request cannot bloat the
        // stored meta or the AI prompt, and normalize empty to null.
        $quotedText = trim($request->query->getString('quotedText'));
        if (mb_strlen($quotedText) > self::MAX_QUOTED_TEXT_LENGTH) {
            $quotedText = mb_substr($quotedText, 0, self::MAX_QUOTED_TEXT_LENGTH);
        }
        $quotedText = '' !== $quotedText ? $quotedText : null;
        // `getInt()` rejects array input (e.g. `quotedMessageId[]=…`) with a clean
        // 400 instead of silently coercing it to 0/1 and resolving the wrong
        // message. 0 (absent/invalid) collapses to null.
        $quotedMessageId = $request->query->getInt('quotedMessageId') ?: null;
        $continueMessageId = $request->query->get('continueMessageId');
        // Explicit opt-out from memory loading + extraction (used by public demo via synaplan.com/try-chat)
        $disableMemories = '1' === $request->query->get('disableMemories', '0');

        // Parse fileIds (can be comma-separated string or single ID)
        $fileIdArray = [];
        if (!empty($fileIds)) {
            $fileIdArray = array_map('intval', array_filter(explode(',', $fileIds)));
        }

        if (empty($messageText) && empty($fileIdArray) && !$continueMessageId) {
            return $this->json(['error' => 'Message or file attachment is required'], Response::HTTP_BAD_REQUEST);
        }

        if (!$chatId) {
            return $this->json(['error' => 'Chat ID is required'], Response::HTTP_BAD_REQUEST);
        }

        // Resolve prompt selection: promptId takes precedence over promptTopic
        if (!$isWidgetMode && $promptId) {
            $promptEntity = $this->em->getRepository(Prompt::class)->find((int) $promptId);
            if (!$promptEntity) {
                return $this->json(['error' => 'PROMPT_NOT_FOUND', 'message' => 'No prompt found with ID '.$promptId], Response::HTTP_NOT_FOUND);
            }
            if (0 !== $promptEntity->getOwnerId() && $promptEntity->getOwnerId() !== $user->getId()) {
                return $this->json(['error' => 'ACCESS_DENIED', 'message' => 'You do not have access to this prompt'], Response::HTTP_FORBIDDEN);
            }
            $fixedTaskPromptTopic = $promptEntity->getTopic();
        } elseif (!$isWidgetMode && $promptTopic) {
            $promptData = $this->promptService->getPromptWithMetadata($promptTopic, $user->getId());
            if (!$promptData) {
                return $this->json(['error' => 'PROMPT_NOT_FOUND', 'message' => 'No prompt found with topic "'.$promptTopic.'"'], Response::HTTP_BAD_REQUEST);
            }
            $fixedTaskPromptTopic = $promptTopic;
        }

        $this->logger->info('StreamController: Received request', [
            'user_id' => $user->getId(),
            'chat_id' => $chatId,
            'has_model_id' => null !== $modelId,
            'model_id' => $modelId,
            'is_again' => $isAgain,
            'file_ids' => $fileIdArray,
            'file_count' => count($fileIdArray),
        ]);

        // Check rate limit BEFORE starting the stream (not widget/guest mode)
        // Send as SSE event so EventSource can parse it (EventSource cannot read JSON error responses)
        $rateLimitError = null;
        if (!$isWidgetMode && !$isGuestMode) {
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
                    // LimitReachedModal offers a "Verify phone" secondary action for
                    // ANONYMOUS users, so this flag MUST reflect actual phone
                    // verification — not email verification (see #839).
                    'phone_verified' => $user->hasVerifiedPhone(),
                ];
            } elseif ($this->costBudgetGateEnabled) {
                $budgetCheck = $this->rateLimitService->checkCostBudget($user);
                if (!$budgetCheck['allowed']) {
                    $rateLimitError = [
                        'status' => 'error',
                        'error' => 'Cost budget exceeded',
                        // Stable machine-readable discriminator so the frontend
                        // does not have to substring-match the human-readable
                        // (i18n-able) error text. See ChatView error handling.
                        'code' => 'COST_BUDGET_EXCEEDED',
                        'limit_type' => 'monthly',
                        'action_type' => 'MESSAGES',
                        'limit' => $budgetCheck['budget'],
                        'used' => $budgetCheck['used_cost'],
                        'remaining' => $budgetCheck['remaining'],
                        'topup_available' => true,
                        'user_level' => $user->getUserLevel(),
                        'phone_verified' => $user->hasVerifiedPhone(),
                    ];
                }
            }
        }

        // Approximate user country from the Cloudflare edge geolocation header
        // (CF-IPCountry). Resolved here, while the Request is in scope, and
        // forwarded into the processing options so the chat handler can add a
        // country-only location-awareness line to the system prompt. Country
        // only by design — it is an imprecise, IP-derived signal. Empty/sentinel
        // values ("XX" unknown, "T1" Tor) are dropped by the handler.
        $clientCountry = $request->headers->get('CF-IPCountry');

        // StreamedResponse für SSE
        $response = new StreamedResponse();
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');

        $response->setCallback(function () use ($user, $messageText, $trackId, $chatId, $includeReasoning, $webSearch, $modelId, $isAgain, $fileIdArray, $isWidgetMode, $isGuestMode, $fixedTaskPromptTopic, $ragGroupKey, $widgetSession, $guestSession, $rateLimitError, $voiceReply, $continueMessageId, $disableMemories, $clientCountry, $quotedText, $quotedMessageId) {
            // Disable output buffering
            while (ob_get_level()) {
                ob_end_clean();
            }
            ob_implicit_flush(1);
            set_time_limit(0);
            // Detach-on-navigation (issues #1142 / #1223 / #1225): keep the turn
            // alive when the client disconnects (chat switch / page leave /
            // reload). The turn finishes in the background and persists its
            // result so it is there on return. sendSSE() already no-ops once the
            // socket is gone, and an EXPLICIT Stop still aborts because it flags
            // the turn via CancellationStore (checked in the stream callback and
            // the media handlers). Only a genuine cancel — never a bare
            // disconnect — ends the turn early.
            ignore_user_abort(true);

            // Phase 0: per-request performance timer.
            // Lives only inside the callback so it doesn't measure connection
            // setup time we have no control over. Forwarded to MessageProcessor
            // via processing options and emitted as a `perf` SSE event before
            // `complete`.
            $perfTimer = new PerfTimer();

            // If rate limit was exceeded, send error as SSE event and return immediately
            // This prevents the user message from being saved when rate limited
            if ($rateLimitError) {
                $this->sendSSE('message', $rateLimitError);

                return;
            }

            $intendedChat = $this->resolveIntendedChatModelForStream(
                null !== $modelId ? (int) $modelId : null,
                $user
            );

            // Helper to save error message
            $saveError = function ($chat, $incomingMessage, string $errorMessage, string $provider = 'system', string $errorType = 'unknown') use ($user, $trackId, $intendedChat) {
                if (!$chat || !$incomingMessage) {
                    return null;
                }

                try {
                    $outgoingMessage = new Message();
                    $outgoingMessage->setUserId($user->getId());
                    $outgoingMessage->setChat($chat);
                    $outgoingMessage->setTrackingId($trackId);
                    $outgoingMessage->setProviderIndex($incomingMessage->getProviderIndex());
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
                    $this->em->flush();

                    $displayProvider = $intendedChat['provider'] ?? $provider;
                    $displayModel = $intendedChat['name'] ?? 'unknown';
                    $outgoingMessage->setMeta('ai_chat_provider', $displayProvider);
                    $outgoingMessage->setMeta('ai_chat_model', $displayModel);
                    if (null !== ($intendedChat['id'] ?? null)) {
                        $outgoingMessage->setMeta('ai_chat_model_id', (string) $intendedChat['id']);
                    }
                    $outgoingMessage->setMeta('error_type', $errorType);

                    $incomingMessage->setTopic('ERROR');
                    $incomingMessage->setStatus('error');

                    $this->em->flush();

                    return $outgoingMessage->getId();
                } catch (\Exception $e) {
                    $this->logger->error('Failed to save error message', ['error' => $e->getMessage()]);

                    return null;
                }
            };

            $chat = null;
            $incomingMessage = null;

            try {
                // Load chat
                $chat = $this->em->getRepository(Chat::class)->find((int) $chatId);
                if (!$chat || $chat->getUserId() !== $user->getId()) {
                    $this->sendSSE('error', ['error' => 'Chat not found or access denied']);

                    return;
                }

                // Guest mode: verify the chat belongs to THIS guest session
                // $isGuestMode is only a flag — keep explicit session check as runtime guard (inconsistent state / refactors).
                // @phpstan-ignore-next-line booleanAnd.rightAlwaysTrue
                if ($isGuestMode && $guestSession) {
                    $sessionChatId = $guestSession->getChatId();
                    if (null === $sessionChatId || $sessionChatId !== (int) $chatId) {
                        $this->sendSSE('error', ['error' => 'Chat does not belong to this guest session']);

                        return;
                    }
                }

                // Rate limit was already checked before stream started
                // (see check before StreamedResponse creation)

                // Load original message for continuation (before creating IncomingMessage)
                $originalOutgoingMessage = null;
                if ($continueMessageId) {
                    $originalOutgoingMessage = $this->em->getRepository(Message::class)->find((int) $continueMessageId);
                    if (!$originalOutgoingMessage
                        || $originalOutgoingMessage->getUserId() !== $user->getId()
                        || $originalOutgoingMessage->getChatId() !== (int) $chatId
                        || 'OUT' !== $originalOutgoingMessage->getDirection()
                    ) {
                        $this->sendSSE('error', ['error' => 'Original message not found']);

                        return;
                    }
                    $messageText = 'Continue your previous response.';
                }

                // Validate the quoted reference (if any): the source message must
                // belong to the same chat and user. An invalid id simply drops the
                // structured back-reference — the quoted text itself is still used.
                $resolvedQuotedMessageId = null;
                if ($quotedText && $quotedMessageId) {
                    $quotedSource = $this->em->getRepository(Message::class)->find((int) $quotedMessageId);
                    if ($quotedSource
                        && $quotedSource->getUserId() === $user->getId()
                        && $quotedSource->getChatId() === (int) $chatId
                    ) {
                        $resolvedQuotedMessageId = (int) $quotedMessageId;
                    }
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
                $incomingMessage->setTopic($continueMessageId ? 'CONTINUE' : 'CHAT');
                $incomingMessage->setLanguage($originalOutgoingMessage ? $originalOutgoingMessage->getLanguage() : 'en');
                $incomingMessage->setText($messageText);
                $incomingMessage->setDirection('IN');
                $incomingMessage->setStatus($continueMessageId ? 'hidden' : 'processing');

                $this->em->persist($incomingMessage);
                $this->em->flush(); // Flush first so message has an ID

                // Persist the quoted reference as message meta so it survives a
                // reload (rendered as a blockquote in the user bubble). Flushed
                // here independently of the em->clear() that the file path below
                // performs, so the meta rows are safe on disk before any detach.
                if ($quotedText) {
                    $incomingMessage->setMeta('quoted_text', $quotedText);
                    if (null !== $resolvedQuotedMessageId) {
                        $incomingMessage->setMeta('quoted_message_id', (string) $resolvedQuotedMessageId);
                    }
                    $this->em->flush();
                }

                // Issue #1024: relay the operator prompt to WhatsApp before AI processing so
                // the full conversation flow (prompt + response) is visible on the WhatsApp side.
                // Guards: widget/guest users are not platform operators; continue-messages use a
                // synthetic prompt that must not be forwarded; isAgain would re-send the same prompt.
                if (!$isWidgetMode && !$isGuestMode && !$continueMessageId && !$isAgain) {
                    $this->messageForwardingService->forwardUserPromptIfNeeded($chat, $messageText);
                }

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
                            $chat = $this->em->getRepository(Chat::class)->find($chatId);
                            if ($chat) {
                                $incomingMessage->setChat($chat);
                            }
                        }

                        // Reload continuation entity after em->clear() so it stays managed
                        if ($originalOutgoingMessage) {
                            $originalOutgoingMessage = $this->em->getRepository(Message::class)->find((int) $continueMessageId);
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
                    'web_search' => $webSearch,
                    'voice_reply' => $voiceReply,
                    'is_continuation' => (bool) $continueMessageId,
                    'perf_timer' => $perfTimer,
                    // Issue #881: defer the ExtractMemoriesCommand dispatch
                    // until after the outgoing assistant message has been
                    // persisted + flushed below. Otherwise the worker can
                    // beat the OUT-row insert and write the memory meta to
                    // the IN row only — the frontend polls the OUT id and
                    // never sees `complete`, so no toast appears in
                    // production. ChatHandler now returns the prepared
                    // ExtractMemoriesCommand in `metadata.extraction_payload`
                    // so we can fire it after the flush.
                    'defer_memory_extraction' => true,
                    // Approximate country (Cloudflare CF-IPCountry header) for the
                    // location-awareness line appended to the chat system prompt.
                    'client_country' => $clientCountry,
                    // Turn id — lets long media generations (Higgsfield video) be
                    // cancelled from another worker via the Stop button, since the
                    // cancel request can't reach this blocked streaming worker.
                    'track_id' => (string) $trackId,
                    // Issue #1146: have MediaGenerationHandler record IMAGES/VIDEOS/
                    // AUDIOS cost into BUSELOG the moment the provider bills us
                    // (success OR cancel), instead of only here after the whole
                    // stream returns. This closes the billing-bypass window where a
                    // client disconnect tore down this worker before recordUsage ran.
                    // The handler sets metadata.usage_recorded so we skip the
                    // duplicate media recordUsage() below.
                    'record_media_usage' => true,
                ];

                // Quoted reference ("Mention in chat"): ChatHandler injects this
                // as a dedicated context block in the system prompt.
                if ($quotedText) {
                    $processingOptions['quoted_text'] = $quotedText;
                    if (null !== $resolvedQuotedMessageId) {
                        $processingOptions['quoted_message_id'] = $resolvedQuotedMessageId;
                    }
                }

                if ($isWidgetMode || $isGuestMode || $disableMemories) {
                    $processingOptions['disable_memories'] = true;
                }

                if ($modelId) {
                    if ($isAgain) {
                        $processingOptions['model_id'] = (int) $modelId;
                        $processingOptions['is_again'] = true;
                    } else {
                        $processingOptions['override_model_id'] = (int) $modelId;
                    }
                    $this->logger->info('StreamController: Model specified', [
                        'model_id' => $modelId,
                        'is_again' => $isAgain,
                    ]);
                }

                // Widget Mode: Force fixed task prompt (no sorting) and disable memories
                if ($isWidgetMode) {
                    $processingOptions['is_widget_mode'] = true;

                    if ($fixedTaskPromptTopic) {
                        $processingOptions['fixed_task_prompt'] = $fixedTaskPromptTopic;
                        $this->logger->info('StreamController: Using fixed task prompt for widget', [
                            'task_prompt' => $fixedTaskPromptTopic,
                        ]);
                    }
                }

                // API prompt selection (promptTopic or promptId)
                if (!$isWidgetMode && $fixedTaskPromptTopic) {
                    $processingOptions['fixed_task_prompt'] = $fixedTaskPromptTopic;
                    $this->logger->info('StreamController: Using API-selected task prompt', [
                        'task_prompt' => $fixedTaskPromptTopic,
                    ]);
                }

                // User-selected knowledge-base folder (RAG group) from the chat composer.
                $processingOptions = $this->applyRagGroupKey($processingOptions, $isWidgetMode, $ragGroupKey);

                // Resolve the chat model that ChatHandler will eventually pick. We mirror its
                // priority order (Again → override → fixed-prompt metadata → DB default) so the
                // streaming/non-streaming routing decision matches the model that actually runs.
                $streamModelId = $this->resolveEffectiveChatModelId(
                    $modelId ? (int) $modelId : null,
                    $processingOptions['fixed_task_prompt'] ?? null,
                    (int) $user->getId(),
                    $intendedChat['id'] ?? null
                );
                $supportsStreaming = true;
                if ($streamModelId) {
                    $supportsStreaming = $this->modelConfigService->supportsStreaming($streamModelId);
                    $this->logger->debug('StreamController: Resolved streaming support', [
                        'model_id' => $streamModelId,
                        'supports_streaming' => $supportsStreaming,
                    ]);
                }

                // Route to streaming or non-streaming handler
                if (!$supportsStreaming) {
                    // Non-streaming models (e.g., o1-preview, o1-mini)
                    $this->handleNonStreamingRequest(
                        $incomingMessage,
                        $processingOptions,
                        $user,
                        $chat,
                        $trackId,
                        $isWidgetMode ? 'WIDGET' : ($isGuestMode ? 'GUEST' : 'WEB'),
                        $streamModelId
                    );

                    return; // Exit callback early
                }

                // Regular streaming path
                // Reasoning buffer for wrapping in <think> tags
                $reasoningBuffer = '';
                $hasReasoningStarted = false;

                // ✨ NEW: JSON detection and parsing
                $jsonBuffer = '';
                $isBufferingJson = false;
                $finishReason = null;

                $result = $this->messageProcessor->processStream(
                    $incomingMessage,
                    // Stream callback - AI streams TEXT directly or structured data (reasoning)
                    function ($chunk) use (&$responseText, &$chunkCount, &$reasoningBuffer, &$hasReasoningStarted, &$jsonBuffer, &$isBufferingJson, &$finishReason, $trackId) {
                        // Detach-on-navigation (issues #1142 / #1223 / #1225): a
                        // bare client disconnect (chat switch, page leave, reload)
                        // must NOT kill the turn — it keeps streaming silently
                        // (sendSSE() no-ops once the socket is gone) so the finished
                        // response is persisted and available on return. Only an
                        // EXPLICIT Stop, which flags the turn via CancellationStore
                        // through /stop-stream (or the guest counterpart
                        // /api/v1/guest/stop-stream), aborts the inline text stream.
                        if ('' !== (string) $trackId
                            && $this->cancellationStore->isCancelled((string) $trackId)) {
                            throw new StreamCancelledException('Stream cancelled by user');
                        }

                        // Handle structured chunk (reasoning models)
                        if (is_array($chunk)) {
                            $type = $chunk['type'] ?? 'content';
                            $content = $chunk['content'] ?? '';

                            // Capture finish signal from provider (e.g. "length" = token limit hit)
                            if ('finish' === $type) {
                                $finishReason = $chunk['finish_reason'] ?? null;

                                return;
                            }

                            if ('reasoning' === $type) {
                                // Accumulate reasoning chunks
                                if (!$hasReasoningStarted) {
                                    $reasoningBuffer = '<think>';
                                    $hasReasoningStarted = true;

                                    // Phase 1e: surface a "thinking" status the
                                    // moment the model starts reasoning so the
                                    // bubble doesn't sit empty for the whole
                                    // reasoning window (Gemini 3.x Pro can
                                    // spend 5-8 s here before emitting any
                                    // visible token).
                                    //
                                    // Intentionally no human-readable `message`
                                    // text: the frontend localizes the label
                                    // via `processing.thinkingTitle` /
                                    // `processing.thinkingDesc` from the user's
                                    // active vue-i18n locale. Sending an
                                    // English string here would leak into the
                                    // bubble description as `customMessage`.
                                    $this->sendSSE('thinking', [
                                        'metadata' => [],
                                        'timestamp' => microtime(true),
                                    ]);
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
                            // Detect and buffer JSON responses (use !== '' to avoid PHP's empty("0") quirk)
                            if (is_string($chunk) && '' !== trim($chunk)) {
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

                            // FIX: Use !== '' instead of !empty() because PHP considers "0" as empty,
                            // which silently drops the character "0" when it arrives as a standalone chunk
                            if ('' !== $chunk) {
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

                // Flush any leftover JSON buffer into the response text.
                //
                // Google/Gemini document generation fix: Gemini follows the
                // officemaker "respond with ONLY JSON" contract literally and
                // its provider emits content as plain strings, so the FIRST
                // chunk starts with '{' and the whole response lands in the
                // JSON buffer. The inline unwrap above only handles BTEXT —
                // a BFILEPATH/BFILETEXT document stayed buffered forever, the
                // post-stream file-generation parse below never saw it, and
                // the turn was persisted as an EMPTY message with no file.
                $streamedVisibleText = $responseText;
                if ($isBufferingJson && '' !== trim($jsonBuffer)) {
                    $responseText .= $jsonBuffer;
                    $jsonBuffer = '';
                    $isBufferingJson = false;
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

                    // Recover original classification topic for correct frontend model selection
                    $classification = $result['classification'] ?? null;
                    $originalTopic = (is_array($classification) && isset($classification['topic']))
                        ? $classification['topic']
                        : null;
                    $originalMediaType = null;
                    if (is_array($classification) && isset($classification['media_type']) && is_string($classification['media_type'])) {
                        $trimmedMedia = trim($classification['media_type']);
                        if ('' !== $trimmedMedia) {
                            $originalMediaType = $trimmedMedia;
                        }
                    }

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

                    // Store error details in metadata (show user's selected chat model, not literal "error")
                    $outgoingMessage->setMeta(
                        'ai_chat_provider',
                        $intendedChat['provider'] ?? ($result['provider'] ?? 'system')
                    );
                    $outgoingMessage->setMeta('ai_chat_model', $intendedChat['name'] ?? 'unknown');
                    if (null !== ($intendedChat['id'] ?? null)) {
                        $outgoingMessage->setMeta('ai_chat_model_id', (string) $intendedChat['id']);
                    }
                    $outgoingMessage->setMeta('error_type', $result['error'] ?? 'unknown');
                    if ($originalTopic) {
                        $outgoingMessage->setMeta('original_topic', $originalTopic);
                    }
                    if (null !== $originalMediaType) {
                        $outgoingMessage->setMeta('original_media_type', $originalMediaType);
                    }

                    // Persist the sorting/routing model when the classifier
                    // ran before the handler exploded — without this, error
                    // rows show only the chat badge live and the sorting
                    // badge never appears, even after refresh (#603).
                    $this->persistClassificationSortingMeta(
                        $outgoingMessage,
                        is_array($classification) ? $classification : null
                    );

                    // Update incoming message
                    $incomingMessage->setTopic('ERROR');
                    $incomingMessage->setStatus('error');

                    $chat->updateTimestamp();
                    $this->em->flush();
                    // Include the nested aiModels shape so the error message
                    // row shows correct model/sorting badges live instead of
                    // only after a page refresh — see issue #603.
                    $completePayload = [
                        'messageId' => $outgoingMessage->getId(),
                        'trackId' => $trackId,
                        'provider' => $intendedChat['provider'] ?? ($result['provider'] ?? 'system'),
                        'model' => $intendedChat['name'] ?? 'unknown',
                        'model_id' => $intendedChat['id'],
                        'topic' => 'ERROR',
                        'originalTopic' => $originalTopic,
                        'originalMediaType' => $originalMediaType,
                        'language' => 'en',
                        'aiModels' => $this->buildAiModelsPayload($outgoingMessage),
                    ];

                    if (isset($result['error_hint'])) {
                        $completePayload['error_hint'] = $result['error_hint'];
                    }

                    $this->sendSSE('complete', $completePayload);

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

                // Multi-task routing (Sprint 3b): a multi-node plan can produce
                // MORE than one output file. Only the task-plan executor sets
                // metadata['files'] (legacy handlers never do), so the single-file
                // path above is unchanged. The first file is already surfaced via
                // metadata['file']; here we surface the remaining files and
                // persist every File entity the reload path needs (issue #1055).
                $taskFileEntities = [];
                if (isset($response['metadata']['files']) && is_array($response['metadata']['files'])) {
                    foreach (array_values($response['metadata']['files']) as $idx => $taskFile) {
                        if (0 === $idx || !is_array($taskFile) || empty($taskFile['path'])) {
                            continue;
                        }
                        $this->sendSSE('file', [
                            'type' => is_string($taskFile['type'] ?? null) ? $taskFile['type'] : 'file',
                            'url' => $taskFile['path'],
                        ]);
                    }
                    $taskFileEntities = $this->persistTaskPlanFiles($response['metadata']['files'], $user->getId());
                }

                if (isset($response['metadata']['links'])) {
                    $this->sendSSE('links', [
                        'links' => $response['metadata']['links'],
                    ]);
                    $this->logger->info('StreamController: Handler provided links');
                }

                $finalText = $responseText;
                $generatedFile = null;

                if ($originalOutgoingMessage) {
                    // Continuation: append new text to the original message
                    $existingText = $originalOutgoingMessage->getText();
                    $originalOutgoingMessage->setText($existingText.$finalText);
                    $this->em->flush();

                    $outgoingMessage = $originalOutgoingMessage;
                } else {
                    // Normal flow: create new outgoing message
                    $outgoingMessage = new Message();
                    $outgoingMessage->setUserId($user->getId());
                    $outgoingMessage->setChat($chat);
                    $outgoingMessage->setTrackingId($trackId);
                    $outgoingMessage->setProviderIndex($incomingMessage->getProviderIndex());
                    $outgoingMessage->setUnixTimestamp(time());
                    $outgoingMessage->setDateTime(date('YmdHis'));
                    $outgoingMessage->setMessageType('WEB');
                    $outgoingMessage->setFile($hasFile);
                    $outgoingMessage->setFilePath($filePath);
                    $outgoingMessage->setFileType($fileType);
                    $outgoingMessage->setTopic($classification['topic']);
                    $outgoingMessage->setLanguage($classification['language']);

                    // Parse JSON response if AI responded in JSON format
                    $jsonContent = $responseText;
                    if (preg_match('/```(?:json)?\s*\n(.*?)\n```/s', $responseText, $matches)) {
                        $jsonContent = trim($matches[1]);
                        $this->logger->info('StreamController: Extracted JSON from markdown code block');
                    }

                    if (str_starts_with(trim($jsonContent), '{')) {
                        try {
                            $jsonData = json_decode($jsonContent, true, 512, JSON_THROW_ON_ERROR);

                            if (isset($jsonData['BFILEPATH']) && isset($jsonData['BFILETEXT'])) {
                                $this->logger->info('StreamController: Detected AI file generation', [
                                    'filename' => $jsonData['BFILEPATH'],
                                ]);

                                $this->sendSSE('generating', [
                                    'message' => 'Datei wird generiert...',
                                    'metadata' => [
                                        'customMessage' => 'Erstelle Datei: '.$jsonData['BFILEPATH'],
                                    ],
                                ]);

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
                            } elseif (isset($jsonData['BTEXT'])) {
                                $finalText = $jsonData['BTEXT'];
                            }
                        } catch (\JsonException $e) {
                            $cleanedJson = preg_replace('/"BFILE":\s*\n/', '"BFILE": 0'."\n", $jsonContent);
                            $cleanedJson = preg_replace('/"BFILE":\s*\r\n/', '"BFILE": 0'."\r\n", $cleanedJson);
                            $cleanedJson = preg_replace('/"BFILE":\s*}/', '"BFILE": 0}', $cleanedJson);

                            try {
                                $jsonData = json_decode($cleanedJson, true, 512, JSON_THROW_ON_ERROR);

                                if (isset($jsonData['BTEXT'])) {
                                    $finalText = $jsonData['BTEXT'];
                                }
                            } catch (\JsonException $e2) {
                                $this->logger->warning('StreamController: Failed to parse JSON', [
                                    'error' => $e2->getMessage(),
                                    'content_preview' => substr($jsonContent, 0, 200),
                                ]);
                            }
                        }
                    }

                    if ('' === trim($finalText)
                        && !empty($response['metadata']['media_job'])
                        && is_array($response['metadata']['media_job'])
                        && 'running' === ($response['metadata']['media_job']['state'] ?? null)) {
                        $finalText = match ($response['metadata']['media_job']['type'] ?? 'video') {
                            'image' => '__IMAGE_GENERATING__',
                            'audio' => '__AUDIO_GENERATING__',
                            default => '__VIDEO_GENERATING__',
                        };
                    }

                    // A document request that produced neither a file nor any
                    // text must not be saved as an empty bubble — surface the
                    // failure marker the frontend translates
                    // (message.fileGenerationFailed) instead.
                    if ('' === trim($finalText) && 'officemaker' === ($classification['topic'] ?? '')) {
                        $finalText = '__FILE_GENERATION_FAILED__';
                        $this->logger->error('StreamController: document generation produced neither file nor text');
                    }

                    // When the whole response was buffered as JSON, the client
                    // never received a single data chunk — push the failure
                    // marker live so the bubble shows the translated error
                    // instead of staying empty until a reload.
                    if ('__FILE_GENERATION_FAILED__' === $finalText && '' === trim($streamedVisibleText)) {
                        $this->sendSSE('data', ['chunk' => $finalText]);
                    }

                    $outgoingMessage->setText($finalText);
                    $outgoingMessage->setDirection('OUT');
                    $outgoingMessage->setStatus('complete');

                    $this->em->persist($outgoingMessage);
                    $this->em->flush();

                    if ($generatedFile) {
                        $outgoingMessage->addFile($generatedFile);
                        $this->em->flush();
                    }

                    // Attach multi-task output files (Sprint 3b) so they
                    // appear in history (getFiles()/files[]) after a reload.
                    if ([] !== $taskFileEntities) {
                        foreach ($taskFileEntities as $taskFileEntity) {
                            $outgoingMessage->addFile($taskFileEntity);
                        }
                        $this->em->flush();
                    }
                }

                $this->logger->info('StreamController: Saving model metadata', [
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

                if (!empty($response['metadata']['response_id'])) {
                    $outgoingMessage->setMeta('openai_response_id', $response['metadata']['response_id']);
                }

                if (!empty($response['metadata']['media_prompt'])) {
                    $outgoingMessage->setMeta('media_prompt', $response['metadata']['media_prompt']);
                }
                if (!empty($response['metadata']['media_type'])) {
                    $outgoingMessage->setMeta('media_type', $response['metadata']['media_type']);
                }
                if (!empty($response['metadata']['media_job']) && is_array($response['metadata']['media_job'])) {
                    $outgoingMessage->setMeta(
                        'media_job',
                        (string) json_encode($response['metadata']['media_job'], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)
                    );

                    // The job was created in the media handler with the INCOMING
                    // message id (the OUT message didn't exist yet). Now that the
                    // OUT message is persisted, rebind the job to it so the
                    // background worker syncs the bubble the user actually sees
                    // on completion (otherwise it stays stuck on "running").
                    $mediaJobKey = $response['metadata']['media_job']['job_id'] ?? null;
                    if (is_string($mediaJobKey) && '' !== $mediaJobKey && null !== $outgoingMessage->getId()) {
                        $rebound = $this->mediaJobService->rebindMessage($mediaJobKey, $outgoingMessage->getId());
                        // Second-chance sync (#1239): a fast render can finish
                        // BEFORE this rebind, while still bound to the IN message
                        // where the terminal sync is deliberately skipped (#1218).
                        // Now that the job points at the OUT message, run the sync
                        // it missed so the finished media reaches the bubble.
                        if (null !== $rebound && $rebound->isTerminal()) {
                            $this->mediaJobMessageSync->syncTerminalState($rebound);
                        }
                    }
                }

                // Multi-task routing: mark the OUT message as a DAG turn so the
                // frontend can offer the simple "Again" (full re-plan) instead of
                // the single-model "Again with…" after a reload.
                if (!empty($response['metadata']['multitask'])) {
                    $outgoingMessage->setMeta('multitask', '1');
                }

                // Persist the per-node render state (capabilities, kinds, states,
                // texts, urls) so the frontend can rebuild task cards on reload
                // without relying on the live SSE stream (issue #1070).
                if (!empty($response['metadata']['task_plan_render'])) {
                    $outgoingMessage->setMeta(
                        'task_plan',
                        (string) json_encode($response['metadata']['task_plan_render'], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)
                    );
                }

                // Multi-task async media (DAG): image/video generation nodes create
                // their MediaJob with the INCOMING user message id (the runner's
                // synthetic message reuses the real IN id, and the OUT message does
                // not exist yet). Unlike the single-task path above, these node jobs
                // are NOT surfaced on the top-level `metadata['media_job']`, so
                // without rebinding them here the background worker's
                // MediaJobMessageSync would sync the finished media onto the USER
                // (IN) bubble — clearing the prompt text and showing the generated
                // image as if the user had sent it (the "generated image appears as
                // the user's prompt after reload" regression). Rebind every node
                // job (the job_id lives on each task card) to the OUT message the
                // user actually sees. A job that already finished while bound to
                // the IN message (fast render losing the race, #1239) is rebound
                // too and immediately given the terminal sync it skipped, so the
                // task card resolves instead of spinning forever.
                if (null !== $outgoingMessage->getId()
                    && isset($response['metadata']['task_plan_render']['cards'])
                    && is_array($response['metadata']['task_plan_render']['cards'])) {
                    foreach ($response['metadata']['task_plan_render']['cards'] as $card) {
                        $cardJobKey = is_array($card) ? ($card['job_id'] ?? null) : null;
                        if (is_string($cardJobKey) && '' !== $cardJobKey) {
                            $rebound = $this->mediaJobService->rebindMessage($cardJobKey, $outgoingMessage->getId());
                            if (null !== $rebound && $rebound->isTerminal()) {
                                $this->mediaJobMessageSync->syncTerminalState($rebound);
                            }
                        }
                    }
                }

                $this->persistOriginalMediaMeta(
                    $outgoingMessage,
                    $classification,
                    $response['metadata'] ?? []
                );

                // Store SORTING model information in MessageMeta (from classification)
                $this->persistClassificationSortingMeta($outgoingMessage, $classification);

                // Store Web Search metadata if web search was used
                if ($webSearch) {
                    $incomingMessage->setMeta('web_search_enabled', 'true');
                    $this->logger->info('StreamController: Web search was enabled for this message');
                }

                // Store if search results were found (will be processed below).
                // For DAG turns the results are not in $result['search_results']
                // (that key only exists when MessageProcessor pre-fetched them on
                // the single-task path). The ResultAssembler now also propagates
                // them into $response['metadata']['search_results'] so both paths
                // are covered by checking both locations.
                $effectiveSearchResults = $result['search_results'] ?? ($response['metadata']['search_results'] ?? null);
                $hasSearchResults = is_array($effectiveSearchResults) && !empty($effectiveSearchResults['results']);
                if ($hasSearchResults) {
                    $searchQuery = $effectiveSearchResults['query'] ?? '';
                    $searchCount = count($effectiveSearchResults['results']);

                    $incomingMessage->setMeta('web_search_query', $searchQuery);
                    $incomingMessage->setMeta('web_search_results_count', (string) $searchCount);
                    $outgoingMessage->setMeta('web_search_query', $searchQuery);
                    $outgoingMessage->setMeta('web_search_results_count', (string) $searchCount);

                    $this->logger->info('StreamController: Stored search results metadata', [
                        'query' => $searchQuery,
                        'results_count' => $searchCount,
                    ]);
                }

                if ($originalOutgoingMessage) {
                    $incomingMessage->setStatus('complete');
                } else {
                    $incomingMessage->setTopic($classification['topic']);
                    $incomingMessage->setLanguage($classification['language']);
                    $incomingMessage->setStatus('complete');
                }

                $chat->updateTimestamp();

                $this->em->flush();

                // Issue #881: now that the outgoing assistant message is
                // persisted (so its OUT row is visible to the worker),
                // fire the deferred ExtractMemoriesCommand. ChatHandler
                // built the payload but skipped the dispatch because we
                // passed `defer_memory_extraction = true` above.
                $this->dispatchDeferredMemoryExtraction($response['metadata'] ?? []);

                if (!$isWidgetMode && !$isGuestMode) {
                    $this->messageForwardingService->forwardIfNeeded($chat, $finalText);
                }

                $this->rateLimitService->recordUsage($user, 'MESSAGES', [
                    'provider' => $response['metadata']['provider'] ?? 'unknown',
                    'model' => $response['metadata']['model'] ?? 'unknown',
                    'model_id' => $response['metadata']['model_id'] ?? null,
                    'usage' => $response['metadata']['usage'] ?? [],
                    'latency' => $response['metadata']['latency'] ?? 0,
                    'chat_id' => $chatId,
                    'source' => $isWidgetMode ? 'WIDGET' : ($isGuestMode ? 'GUEST' : 'WEB'),
                    'response_text' => $finalText,
                    'input_text' => $messageText,
                ]);

                // Record AI-generated media usage (IMAGES, VIDEOS, AUDIOS) separately.
                // Issue #1146: MediaGenerationHandler now records this itself (the
                // instant the provider bills us, so a client disconnect can't skip
                // it). It flags that via metadata.usage_recorded — when set we must
                // NOT record again here or we would double-charge the user.
                $mediaType = $response['metadata']['media_type'] ?? null;
                $mediaUsageAlreadyRecorded = !empty($response['metadata']['usage_recorded']);
                if ($mediaType && !$mediaUsageAlreadyRecorded) {
                    $mediaAction = match ($mediaType) {
                        'image' => 'IMAGES',
                        'video' => 'VIDEOS',
                        'audio' => 'AUDIOS',
                        default => null,
                    };

                    if ($mediaAction) {
                        $mediaBytes = $generatedFile ? $generatedFile->getFileSize() : 0;

                        $this->rateLimitService->recordUsage($user, $mediaAction, [
                            'provider' => $response['metadata']['provider'] ?? 'unknown',
                            'model' => $response['metadata']['model'] ?? 'unknown',
                            'model_id' => $response['metadata']['model_id'] ?? null,
                            'chat_id' => $chatId,
                            'source' => $isWidgetMode ? 'WIDGET' : ($isGuestMode ? 'GUEST' : 'WEB'),
                            'response_bytes' => $mediaBytes,
                            'input_text' => $messageText,
                            'media_usage' => $response['metadata']['media_usage'] ?? [],
                        ]);
                    }
                }

                // Get search results if available (uses the effective source computed above)
                $searchResults = $this->formatSearchResultsForSse($effectiveSearchResults ?? null);
                if (null !== $searchResults) {
                    $this->logger->info('StreamController: Including search results', [
                        'results_count' => count($searchResults),
                        'query' => $effectiveSearchResults['query'] ?? '',
                    ]);
                }

                // Send complete event (WITHOUT againData - frontend handles this)
                // aiModels mirrors the nested shape returned by the history
                // endpoint so badges (chat + sorting) populate live instead of
                // only after a page refresh — see issue #603.
                // Normalise model_id to int|null so the flat SSE field matches
                // the shape of the history endpoint and the nested aiModels
                // payload (PR #833 review, Copilot #1). $modelId comes straight
                // from Request::query->get() and can be a numeric string, or
                // junk like "abc"; `normalizeModelId()` refuses to cast
                // non-numeric input to 0 and logs it instead of silently
                // corrupting downstream data.
                $rawChatModelId = $response['metadata']['model_id'] ?? $modelId ?? null;
                $completeChatModelId = $this->normalizeModelId($rawChatModelId, 'streaming_complete');

                // `originalTopic` / `originalMediaType` mirror the same fields
                // the error path already ships and the history endpoint reads
                // (`ChatController::getMessages`). The frontend `complete`
                // handler in ChatView assigns them onto `message.*` so
                // `mediaHintFromClassificationTopic('mediamaker', 'audio')`
                // returns `audio` live — fixing the badge-label flip
                // documented in issue #624.
                $originalTopic = $outgoingMessage->getMeta('original_topic');
                $originalMediaType = $outgoingMessage->getMeta('original_media_type');

                $completeData = [
                    'messageId' => $outgoingMessage->getId(),
                    'trackId' => $trackId,
                    'provider' => $response['metadata']['provider'] ?? 'test',
                    'model' => $response['metadata']['model'] ?? 'unknown',
                    'model_id' => $completeChatModelId,
                    'topic' => $classification['topic'],
                    'originalTopic' => $originalTopic,
                    'originalMediaType' => $originalMediaType,
                    'language' => $classification['language'],
                    'searchResults' => $searchResults,
                    'aiModels' => $this->buildAiModelsPayload($outgoingMessage),
                ];

                if ('length' === $finishReason) {
                    $completeData['truncated'] = true;
                }

                if (!empty($response['metadata']['media_job']) && is_array($response['metadata']['media_job'])) {
                    $completeData['mediaJob'] = $response['metadata']['media_job'];
                }

                // Include memories used for this response
                // Send only memory IDs (frontend loads full memories from store)
                if (isset($response['metadata']['memories']) && is_array($response['metadata']['memories'])) {
                    $completeData['memoryIds'] = array_map(fn ($m) => $m['id'], $response['metadata']['memories']);
                    $this->logger->info('StreamController: Including memory IDs in complete event', [
                        'count' => count($response['metadata']['memories']),
                    ]);
                }

                // Include feedbacks used for this response
                // Send only feedback IDs (frontend loads full feedbacks from store)
                if (isset($response['metadata']['feedbacks']) && is_array($response['metadata']['feedbacks'])) {
                    $completeData['feedbackIds'] = array_filter(
                        array_map(fn ($f) => $f['id'] ?? null, $response['metadata']['feedbacks']),
                        fn ($id) => null !== $id
                    );
                    $this->logger->debug('StreamController: Including feedback IDs in complete event', [
                        'count' => count($completeData['feedbackIds']),
                    ]);
                }

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

                // === Voice Reply: TTS Generation (Phase 3) ===
                // Generate MP3 audio BEFORE sending complete event
                // (frontend closes EventSource on 'complete', so audio must arrive first)
                if ($voiceReply && !empty($responseText)) {
                    // GUARD 1: Skip voice reply for media generation (image/video/audio)
                    $handlerIntent = $classification['intent'] ?? $classification['topic'] ?? 'chat';
                    if (in_array($handlerIntent, ['image_generation', 'video_generation', 'audio_generation', 'mediamaker'], true)) {
                        $this->logger->info('StreamController: Skipping voice reply for media generation', [
                            'intent' => $handlerIntent,
                        ]);
                        $voiceReply = false;
                    }

                    if ($voiceReply) {
                        $limitCheck = $this->rateLimitService->checkLimit($user, 'AUDIOS');
                        if (!$limitCheck['allowed']) {
                            $this->logger->warning('StreamController: Voice reply skipped - rate limit exceeded', [
                                'user_id' => $user->getId(),
                            ]);
                            $voiceReply = false;
                        }
                    }
                }

                if ($voiceReply && !empty($responseText)) {
                    try {
                        $language = $classification['language'] ?? 'en';

                        $this->sendSSE('tts_generating', ['language' => $language]);

                        $ttsText = TtsTextSanitizer::sanitize($responseText);
                        $ttsText = mb_substr($ttsText, 0, 4000);

                        if (!empty(trim($ttsText))) {
                            $ttsResult = $this->aiFacade->synthesize($ttsText, $user->getId(), [
                                'format' => 'mp3',
                                'language' => $language,
                            ]);

                            $audioUrl = '/api/v1/files/uploads/'.$ttsResult['relativePath'];

                            $outgoingMessage->setFile(1);
                            $outgoingMessage->setFilePath($audioUrl);
                            $outgoingMessage->setFileType('audio');

                            // Persist the TTS provider/model on the
                            // outgoing message so the history endpoint
                            // (and any later page reload) surfaces the
                            // *audio* model — not the chat LLM — under
                            // the "Audio Model" badge. Fixes #583 and
                            // its post-refresh sibling reports.
                            $ttsProvider = $ttsResult['provider'] ?? null;
                            $ttsModelName = $ttsResult['model'] ?? null;
                            $ttsModelId = $ttsResult['model_id'] ?? null;
                            if (null !== $ttsProvider) {
                                $outgoingMessage->setMeta('ai_audio_provider', (string) $ttsProvider);
                            }
                            if (null !== $ttsModelName) {
                                $outgoingMessage->setMeta('ai_audio_model', (string) $ttsModelName);
                            }
                            if (null !== $ttsModelId && '' !== (string) $ttsModelId) {
                                $outgoingMessage->setMeta('ai_audio_model_id', (string) $ttsModelId);
                            }
                            $this->em->flush();

                            // Refresh the `aiModels` payload that was
                            // pre-built before TTS ran, so the live SSE
                            // `complete` event already carries the
                            // audio badge — no page reload required.
                            $completeData['aiModels'] = $this->buildAiModelsPayload($outgoingMessage);

                            $this->sendSSE('audio', [
                                'url' => $audioUrl,
                                'provider' => $ttsProvider,
                                'model' => $ttsModelName,
                                'model_id' => $this->normalizeModelId($ttsModelId, 'sse_audio_event'),
                            ]);

                            $this->rateLimitService->recordUsage($user, 'AUDIOS', [
                                'provider' => $ttsProvider ?? 'unknown',
                                'model' => $ttsModelName ?? 'unknown',
                                'model_id' => $ttsModelId,
                                'media_usage' => [
                                    'characters' => $ttsResult['text_length'] ?? mb_strlen($ttsText),
                                ],
                            ]);

                            $this->logger->info('StreamController: Voice reply generated', [
                                'url' => $audioUrl,
                                'provider' => $ttsProvider ?? 'unknown',
                                'model' => $ttsModelName ?? 'unknown',
                            ]);
                        }
                    } catch (\Throwable $e) {
                        $this->logger->warning('StreamController: Voice reply TTS failed', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Widget Mode: Increment session message count
                // $isWidgetMode is only a flag — keep explicit widget session check as runtime guard.
                // @phpstan-ignore-next-line booleanAnd.rightAlwaysTrue
                if ($isWidgetMode && $widgetSession) {
                    $this->widgetSessionService->incrementMessageCount($widgetSession);
                    $this->logger->info('Widget session message count incremented', [
                        'session_id' => $widgetSession->getSessionId(),
                        'message_count' => $widgetSession->getMessageCount(),
                    ]);
                }

                // Guest Mode: Increment count and send remaining BEFORE complete
                // (complete closes the EventSource on the client)
                // $isGuestMode is only a flag — keep explicit session check as runtime guard.
                // @phpstan-ignore-next-line booleanAnd.rightAlwaysTrue
                if ($isGuestMode && $guestSession) {
                    $this->guestSessionService->attachChat($guestSession, (int) $chatId);
                    $this->guestSessionService->incrementCount($guestSession);
                    $this->sendSSE('guest_remaining', [
                        'remaining' => $this->guestSessionService->getRemainingMessages($guestSession),
                        'maxMessages' => $guestSession->getMaxMessages(),
                        'limitReached' => $this->guestSessionService->isLimitReached($guestSession),
                    ]);
                }

                // Phase 0: emit per-request performance breakdown so the
                // frontend (gated by localStorage.synaplanDebug) and
                // benchmarks can see where wall-clock time was spent.
                // Sent before `complete` so the client picks it up while the
                // EventSource is still open.
                $this->sendSSE('perf', $perfTimer->toArray());

                $this->sendSSE('complete', $completeData);

                usleep(100000);

                $this->logger->info('Streamed message processed', [
                    'user_id' => $user->getId(),
                    'message_id' => $outgoingMessage->getId(),
                    'topic' => $classification['topic'],
                ]);
            } catch (\App\AI\Exception\ProviderException $e) {
                $this->logger->error('AI Provider failed', [
                    'user_id' => $user->getId(),
                    'error' => $e->getMessage(),
                    'provider' => $e->getProviderName(),
                    'context' => $e->getContext(),
                ]);

                $messageId = $saveError($chat, $incomingMessage, $e->getMessage(), $e->getProviderName(), 'provider_error');

                $errorData = [
                    'error' => $e->getMessage(),
                    'provider' => $intendedChat['provider'] ?? $e->getProviderName(),
                    'model' => $intendedChat['name'] ?? 'unknown',
                    'model_id' => $intendedChat['id'],
                    'topic' => 'ERROR',
                    'trackId' => $trackId,
                ];

                if ($messageId) {
                    $errorData['messageId'] = $messageId;
                }

                // Add installation instructions if available
                if ($context = $e->getContext()) {
                    $errorData['install_command'] = $context['install_command'] ?? null;
                    $errorData['suggested_models'] = $context['suggested_models'] ?? null;
                }

                $this->sendSSE('error', $errorData);
            } catch (StreamCancelledException) {
                // Explicit user cancel (/stop-stream flagged the turn) — not an
                // error and not a disconnect. The frontend persists the partial
                // answer via /save-cancelled, so just end the turn silently.
                $this->logger->info('Stream stopped - cancelled by user', [
                    'user_id' => $user->getId(),
                    'track_id' => (string) $trackId,
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Streaming failed', [
                    'user_id' => $user->getId(),
                    'error' => $e->getMessage(),
                ]);

                $messageId = $saveError($chat, $incomingMessage, $e->getMessage(), 'system', 'exception');

                $errorData = [
                    'error' => 'Failed to process message: '.$e->getMessage(),
                    'provider' => $intendedChat['provider'] ?? 'system',
                    'model' => $intendedChat['name'] ?? 'unknown',
                    'model_id' => $intendedChat['id'],
                    'topic' => 'ERROR',
                    'trackId' => $trackId,
                ];

                if ($messageId) {
                    $errorData['messageId'] = $messageId;
                }

                $this->sendSSE('error', $errorData);
            }
        });

        return $response;
    }

    /**
     * Resolve the chat model the user intended for this stream (explicit modelId or default CHAT from config).
     *
     * @return array{id: ?int, name: ?string, provider: ?string}
     */
    private function resolveIntendedChatModelForStream(?int $modelId, User $user): array
    {
        $id = $modelId
            ?? $this->modelConfigService->getDefaultModel('chat', $user->getId())
            ?? $this->modelConfigService->getDefaultModel('chat', 0);

        if (!$id) {
            return ['id' => null, 'name' => null, 'provider' => null];
        }

        return [
            'id' => $id,
            'name' => $this->modelConfigService->getModelName($id),
            'provider' => $this->modelConfigService->getProviderForModel($id),
        ];
    }

    /**
     * Predict which chat model ChatHandler will pick for this request.
     *
     * Priority mirrors {@see ChatHandler::handle()} / {@see ChatHandler::handleStream()}:
     *   1. Explicit query-param model (`Again` or override).
     *   2. `aiModel` metadata of a fixed task prompt (widget / API `promptTopic`).
     *   3. The user's DB default chat model.
     *
     * Used to decide before classification whether the streaming or the
     * non-streaming SSE path should run.
     */
    private function resolveEffectiveChatModelId(
        ?int $explicitModelId,
        ?string $fixedTaskPromptTopic,
        int $userId,
        mixed $defaultChatModelId,
    ): ?int {
        if (null !== $explicitModelId && $explicitModelId > 0) {
            return $explicitModelId;
        }

        if (null !== $fixedTaskPromptTopic && '' !== $fixedTaskPromptTopic) {
            try {
                $promptData = $this->promptService->getPromptWithMetadata($fixedTaskPromptTopic, $userId);
                $promptModelId = (int) ($promptData['metadata']['aiModel'] ?? 0);
                if ($promptModelId > 0) {
                    return $promptModelId;
                }
            } catch (\Throwable $e) {
                // Prompt resolution is best-effort here; fall through to the default.
                $this->logger->debug('StreamController: Could not pre-resolve prompt model for streaming routing', [
                    'topic' => $fixedTaskPromptTopic,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (is_int($defaultChatModelId) && $defaultChatModelId > 0) {
            return $defaultChatModelId;
        }
        if (is_string($defaultChatModelId) && ctype_digit($defaultChatModelId) && (int) $defaultChatModelId > 0) {
            return (int) $defaultChatModelId;
        }

        return null;
    }

    /**
     * Handle non-streaming requests for models that don't support streaming (e.g., o1-preview).
     */
    private function handleNonStreamingRequest(
        Message $message,
        array $options,
        User $user,
        Chat $chat,
        int|string $trackId,
        string $source,
        ?int $intendedModelId,
    ): void {
        try {
            // Send processing status
            $this->sendSSE('status', ['message' => 'Processing with non-streaming model...']);

            // Process message without streaming
            $result = $this->messageProcessor->process(
                $message,
                $options,
                function (array $statusUpdate): void {
                    if ('complete' === $statusUpdate['status']) {
                        return;
                    }

                    $this->sendSSE($statusUpdate['status'], [
                        'message' => $statusUpdate['message'],
                        'metadata' => $statusUpdate['metadata'] ?? [],
                        'timestamp' => $statusUpdate['timestamp'],
                    ]);
                }
            );

            if (!$result['success']) {
                $errorMessage = (string) ($result['error'] ?? 'Failed to process message');
                $failedClassification = $result['classification'] ?? null;
                $originalTopic = null;
                $originalMediaType = null;
                if (is_array($failedClassification)) {
                    if (isset($failedClassification['topic']) && is_string($failedClassification['topic'])) {
                        $originalTopic = $failedClassification['topic'];
                    }
                    if (isset($failedClassification['media_type']) && is_string($failedClassification['media_type'])
                        && '' !== trim($failedClassification['media_type'])) {
                        $originalMediaType = trim($failedClassification['media_type']);
                    }
                }

                $outgoingMessage = new Message();
                $outgoingMessage->setUserId($user->getId());
                $outgoingMessage->setChat($chat);
                $outgoingMessage->setTrackingId((int) $trackId);
                $outgoingMessage->setProviderIndex($message->getProviderIndex());
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
                $this->em->flush();

                $displayProvider = $intendedModelId
                    ? ($this->modelConfigService->getProviderForModel($intendedModelId) ?? ($result['provider'] ?? 'system'))
                    : ($result['provider'] ?? 'system');
                $displayModel = $intendedModelId
                    ? ($this->modelConfigService->getModelName($intendedModelId) ?? 'unknown')
                    : 'unknown';

                $outgoingMessage->setMeta('ai_chat_provider', $displayProvider);
                $outgoingMessage->setMeta('ai_chat_model', $displayModel);
                if (null !== $intendedModelId) {
                    $outgoingMessage->setMeta('ai_chat_model_id', (string) $intendedModelId);
                }
                $outgoingMessage->setMeta('error_type', $errorMessage);
                if (null !== $originalTopic) {
                    $outgoingMessage->setMeta('original_topic', $originalTopic);
                }
                if (null !== $originalMediaType) {
                    $outgoingMessage->setMeta('original_media_type', $originalMediaType);
                }

                // Mirror the streaming error branch (see issue #603): if the
                // classifier ran before the non-streaming handler failed, keep
                // the sorting badge live and after refresh by persisting the
                // routing model meta on the error row.
                $this->persistClassificationSortingMeta($outgoingMessage, $failedClassification);

                $message->setTopic('ERROR');
                $message->setStatus('error');
                $chat->updateTimestamp();
                $this->em->flush();

                $this->sendSSE('data', ['chunk' => $errorMessage]);
                $this->sendSSE('complete', [
                    'messageId' => $outgoingMessage->getId(),
                    'trackId' => $trackId,
                    'provider' => $displayProvider,
                    'model' => $displayModel,
                    'model_id' => $intendedModelId,
                    'topic' => 'ERROR',
                    'originalTopic' => $originalTopic,
                    'originalMediaType' => $originalMediaType,
                    'language' => 'en',
                    'aiModels' => $this->buildAiModelsPayload($outgoingMessage),
                ]);

                return;
            }

            // Unpack nested response (process() returns response under 'response' key)
            $response = $result['response'] ?? [];

            // Detect if we are falling back to flat structure; this may indicate an unexpected response format
            $usedFlatContent = !isset($response['content']) && \array_key_exists('content', $result);
            $usedFlatMetadata = !isset($response['metadata']) && \array_key_exists('metadata', $result);

            if ($usedFlatContent || $usedFlatMetadata) {
                $this->logger->warning('StreamController: Fell back to flat response structure from process()', [
                    'usedFlatContent' => $usedFlatContent,
                    'usedFlatMetadata' => $usedFlatMetadata,
                    'messageId' => $message->getId(),
                ]);
            }

            $content = $response['content'] ?? $result['content'] ?? '';
            $metadata = $response['metadata'] ?? $result['metadata'] ?? [];
            $classification = $result['classification'] ?? [];

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

            // Send file event if media was generated
            if (isset($metadata['file'])) {
                $this->sendSSE('file', [
                    'type' => $metadata['file']['type'] ?? 'image',
                    'url' => $metadata['file']['path'] ?? '',
                ]);
            }

            $outgoingMessage = new Message();
            $outgoingMessage->setUserId($user->getId());
            $outgoingMessage->setChat($chat);
            $outgoingMessage->setTrackingId((int) $trackId);
            $outgoingMessage->setProviderIndex($message->getProviderIndex());
            $outgoingMessage->setUnixTimestamp(time());
            $outgoingMessage->setDateTime(date('YmdHis'));
            $outgoingMessage->setMessageType('WEB');
            $outgoingMessage->setFile(isset($metadata['file']) ? 1 : 0);
            $outgoingMessage->setFilePath($metadata['file']['path'] ?? '');
            $outgoingMessage->setFileType($metadata['file']['type'] ?? '');
            $outgoingMessage->setTopic((string) ($classification['topic'] ?? $message->getTopic()));
            $outgoingMessage->setLanguage((string) ($classification['language'] ?? $message->getLanguage()));
            $outgoingMessage->setText($content);
            $outgoingMessage->setDirection('OUT');
            $outgoingMessage->setStatus('complete');

            $this->em->persist($outgoingMessage);
            $this->em->flush();

            if (!empty($metadata['provider']) || !empty($metadata['model'])) {
                $outgoingMessage->setMeta('ai_chat_provider', (string) ($metadata['provider'] ?? ''));
                $outgoingMessage->setMeta('ai_chat_model', (string) ($metadata['model'] ?? ''));
                if (isset($metadata['model_id']) && '' !== $metadata['model_id']) {
                    $outgoingMessage->setMeta('ai_chat_model_id', (string) $metadata['model_id']);
                }
            }

            if (!empty($metadata['usage'])) {
                $outgoingMessage->setMeta('ai_chat_usage', json_encode($metadata['usage']));
            }

            if (!empty($metadata['response_id'])) {
                $outgoingMessage->setMeta('openai_response_id', $metadata['response_id']);
            }

            $this->persistClassificationSortingMeta($outgoingMessage, $classification);

            // Mirror the streaming branch above: keep MEDIAMAKER meta
            // consistent for non-streaming callers (email, generic webhook)
            // so a later history fetch surfaces the right "Audio Model"
            // badge and the right capability for the Again dropdown.
            $this->persistOriginalMediaMeta($outgoingMessage, $classification, $metadata);

            // Mirror the streaming branch: flag DAG turns for the history API.
            if (!empty($metadata['multitask'])) {
                $outgoingMessage->setMeta('multitask', '1');
            }

            // Mirror the streaming branch: persist per-node render state for reload.
            if (!empty($metadata['task_plan_render'])) {
                $outgoingMessage->setMeta(
                    'task_plan',
                    (string) json_encode($metadata['task_plan_render'], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)
                );
            }

            // Mirror the streaming branch: rebind every async node job to the OUT
            // message, and give a job that already finished while bound to the IN
            // message (fast render losing the race, #1239) its missed terminal
            // sync so the task card resolves instead of spinning forever.
            if (null !== $outgoingMessage->getId()
                && isset($metadata['task_plan_render']['cards'])
                && is_array($metadata['task_plan_render']['cards'])) {
                foreach ($metadata['task_plan_render']['cards'] as $card) {
                    $cardJobKey = is_array($card) ? ($card['job_id'] ?? null) : null;
                    if (is_string($cardJobKey) && '' !== $cardJobKey) {
                        $rebound = $this->mediaJobService->rebindMessage($cardJobKey, $outgoingMessage->getId());
                        if (null !== $rebound && $rebound->isTerminal()) {
                            $this->mediaJobMessageSync->syncTerminalState($rebound);
                        }
                    }
                }
            }

            if (!empty($options['web_search'])) {
                $message->setMeta('web_search_enabled', 'true');
            }

            // Mirror the streaming path: also check metadata['search_results'] so DAG
            // turns that searched via WebSearchRunner (not MessageProcessor) get their
            // web_search_query/count metas set and the Sources dropdown populated.
            $effectiveSearchResults = $result['search_results'] ?? ($metadata['search_results'] ?? null);
            $hasSearchResults = is_array($effectiveSearchResults) && !empty($effectiveSearchResults['results']);
            if ($hasSearchResults) {
                $searchQuery = $effectiveSearchResults['query'] ?? '';
                $searchCount = count($effectiveSearchResults['results']);

                $message->setMeta('web_search_query', $searchQuery);
                $message->setMeta('web_search_results_count', (string) $searchCount);
                $outgoingMessage->setMeta('web_search_query', $searchQuery);
                $outgoingMessage->setMeta('web_search_results_count', (string) $searchCount);
            }

            $message->setTopic((string) ($classification['topic'] ?? $message->getTopic()));
            $message->setLanguage((string) ($classification['language'] ?? $message->getLanguage()));
            $message->setStatus('complete');
            $chat->updateTimestamp();
            $this->em->flush();

            // Issue #881: outgoing message is now persisted, so the
            // worker can safely look it up via tracking_id when it
            // writes the extracted_memories meta. Fire the deferred
            // dispatch the same way the streaming branch does.
            $this->dispatchDeferredMemoryExtraction($metadata);

            if ('WEB' === $source) {
                $this->messageForwardingService->forwardIfNeeded($chat, $content);
            }

            $this->rateLimitService->recordUsage($user, 'MESSAGES', [
                'provider' => $metadata['provider'] ?? 'unknown',
                'model' => $metadata['model'] ?? 'unknown',
                'model_id' => $metadata['model_id'] ?? null,
                'usage' => $metadata['usage'] ?? [],
                'latency' => $metadata['latency'] ?? 0,
                'chat_id' => $chat->getId(),
                'source' => $source,
                'response_text' => $content,
                'input_text' => $message->getText(),
            ]);

            // $metadata['model_id'] provenance varies per provider (some emit
            // string, some int, occasionally non-numeric junk). Coerce once
            // so the flat SSE field and the nested payload stay in sync.
            $nonStreamingModelId = $this->normalizeModelId($metadata['model_id'] ?? null, 'non_streaming_complete');

            // Mirror the streaming branch so the non-streaming `complete`
            // event also carries `originalTopic` / `originalMediaType` —
            // keeps the badge label and Again model selection consistent
            // for callers that share this SSE shape (see issue #624).
            $nonStreamingOriginalTopic = $outgoingMessage->getMeta('original_topic');
            $nonStreamingOriginalMediaType = $outgoingMessage->getMeta('original_media_type');

            $completeData = [
                'messageId' => $outgoingMessage->getId(),
                'provider' => $metadata['provider'] ?? 'unknown',
                'model' => $metadata['model'] ?? 'unknown',
                'model_id' => $nonStreamingModelId,
                'trackId' => $trackId,
                'topic' => $classification['topic'] ?? null,
                'originalTopic' => $nonStreamingOriginalTopic,
                'originalMediaType' => $nonStreamingOriginalMediaType,
                'language' => $classification['language'] ?? null,
                'searchResults' => $this->formatSearchResultsForSse($effectiveSearchResults ?? null),
                'aiModels' => $this->buildAiModelsPayload($outgoingMessage),
            ];

            if (isset($metadata['memories']) && is_array($metadata['memories'])) {
                $completeData['memoryIds'] = array_map(fn ($memory) => $memory['id'], $metadata['memories']);
            }

            if (isset($metadata['feedbacks']) && is_array($metadata['feedbacks'])) {
                $completeData['feedbackIds'] = array_filter(
                    array_map(fn ($feedback) => $feedback['id'] ?? null, $metadata['feedbacks']),
                    fn ($id) => null !== $id
                );
            }

            $this->sendSSE('complete', $completeData);
        } catch (\Exception $e) {
            $this->logger->error('Non-streaming processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->sendSSE('error', ['error' => 'Failed to process: '.$e->getMessage()]);
        }
    }

    /**
     * Push the deferred ExtractMemoriesCommand prepared by ChatHandler
     * onto the messenger bus.
     *
     * Issue #881 race fix: ChatHandler used to dispatch the command
     * itself at the end of `handleStream()` / `handle()`, which races
     * the StreamController flush of the outgoing assistant message. On
     * a fast worker (empty queue, low load) the worker would look up
     * the OUT row by tracking_id, find nothing, and write the
     * `extracted_memories` meta to the IN row only. The frontend polls
     * the OUT message id from the SSE `complete` event, so the poll
     * always returned `pending` and the memory toast never appeared.
     *
     * Now ChatHandler returns the prepared command in
     * `metadata.extraction_payload` and we fire it here, AFTER
     * `$this->em->flush()` has made the OUT row visible. Dispatch +
     * logging + swallow-on-failure all live in
     * {@see MemoryExtractionDispatcher} so this method only translates
     * the metadata-array contract into a typed command (Copilot review
     * of PR #939: keep the dispatch policy in one service so this path
     * and the synchronous ChatHandler fallback cannot drift on logging,
     * retry semantics, or future middleware).
     *
     * @param array<string, mixed> $metadata The `metadata` block from the inference result
     */
    private function dispatchDeferredMemoryExtraction(array $metadata): void
    {
        $payload = $metadata['extraction_payload'] ?? null;
        if (!$payload instanceof ExtractMemoriesCommand) {
            return;
        }

        $this->memoryExtractionDispatcher->dispatch($payload);
    }

    /**
     * Scope this turn's RAG retrieval to a user-selected knowledge-base
     * folder (file group key) picked in the chat composer.
     *
     * Widget mode must never honour a caller-supplied group key: the widget
     * is locked to its own configuration, so an embedded page cannot widen
     * or redirect retrieval by sending `ragGroupKey`. An empty/absent key is
     * a no-op (default, unscoped retrieval).
     *
     * @param array<string, mixed> $processingOptions
     *
     * @return array<string, mixed>
     */
    private function applyRagGroupKey(array $processingOptions, bool $isWidgetMode, ?string $ragGroupKey): array
    {
        if ($isWidgetMode || empty($ragGroupKey)) {
            return $processingOptions;
        }

        $processingOptions['rag_group_key'] = $ragGroupKey;
        $this->logger->info('StreamController: Scoping RAG to user-selected group', [
            'rag_group_key' => $ragGroupKey,
        ]);

        return $processingOptions;
    }

    /**
     * @param array<string, mixed>|null $rawSearchResults
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function formatSearchResultsForSse(?array $rawSearchResults): ?array
    {
        if (empty($rawSearchResults['results']) || !is_array($rawSearchResults['results'])) {
            return null;
        }

        return array_map(static function (array $result): array {
            return [
                'title' => $result['title'] ?? '',
                'url' => $result['url'] ?? '',
                'description' => $result['description'] ?? '',
                'published' => $result['age'] ?? null,
                'source' => $result['profile']['name'] ?? null,
                'thumbnail' => $result['thumbnail'] ?? null,
            ];
        }, $rawSearchResults['results']);
    }

    /**
     * Normalise a raw model_id (from query string, provider metadata, or
     * message meta) to an int or null.
     *
     * Providers are inconsistent: some emit ints, some numeric strings, and
     * some occasionally ship non-numeric junk. A blind `(int)` cast turns
     * `"abc"` into `0`, which silently corrupts the SSE `model_id` field and
     * any downstream consumer — PR #833 review flagged this explicitly. We
     * accept only numeric input and log the rest so we see it in monitoring
     * instead of manifesting as mystery rows in the history endpoint.
     *
     * @param mixed $raw raw value from request/query/metadata
     */
    private function normalizeModelId(mixed $raw, string $context): ?int
    {
        if (null === $raw || '' === $raw) {
            return null;
        }

        if (is_int($raw)) {
            return $raw;
        }

        if (is_string($raw) && is_numeric($raw)) {
            return (int) $raw;
        }

        $this->logger->warning('StreamController: non-numeric model_id received, discarding', [
            'context' => $context,
            'type' => get_debug_type($raw),
            'value' => is_scalar($raw) ? (string) $raw : null,
        ]);

        return null;
    }

    /**
     * Persist the routing/sorting model the classifier picked onto the
     * outgoing message so the "Sorting Model" badge appears live in the
     * SSE complete event and survives a page reload.
     *
     * Without this, error rows (e.g. ProviderException for an unpulled
     * Ollama model, or a failed image generation) drop the sorting badge
     * even though the classifier ran — the row shows only the chat badge
     * live AND after refresh, since the meta was never persisted.
     *
     * Used from both the streaming `success: false` branch and the
     * non-streaming error branch in `handleNonStreamingRequest()`. See
     * issue #603.
     *
     * @param array<string, mixed>|null $classification
     */
    private function persistClassificationSortingMeta(Message $message, ?array $classification): void
    {
        if (!is_array($classification)) {
            return;
        }

        if (!empty($classification['sorting_provider'])) {
            $message->setMeta('ai_sorting_provider', (string) $classification['sorting_provider']);
        }
        if (!empty($classification['sorting_model_name'])) {
            $message->setMeta('ai_sorting_model', (string) $classification['sorting_model_name']);
        }
        if (!empty($classification['sorting_model_id'])) {
            $message->setMeta('ai_sorting_model_id', (string) $classification['sorting_model_id']);
        }
    }

    /**
     * Persist `original_topic` / `original_media_type` meta on a MEDIAMAKER
     * outgoing message so the chat-message badge label (#583) and the
     * "Again" model dropdown surface a stable media type both during live
     * SSE streaming and after the page reloads history from the DB.
     *
     * Without this, MEDIAMAKER audio falls back to mediaHint=null live
     * (no `audio` part is yet in `message.parts` — see issue #625) which
     * surfaces "Chat Model" and a CHAT-capability prediction for the Again
     * dropdown. After reload, the audio file lands in parts and the badge
     * flips to "Audio Model" / TEXT2SOUND. This helper fixes the flip by
     * pre-persisting the original media intent. See issue #624.
     *
     * No-op for non-mediamaker classifications so we don't leak this meta
     * onto regular chat replies.
     *
     * @param array{topic?: ?string, media_type?: ?string}|array<string, mixed> $classification
     * @param array{media_type?: ?string}|array<string, mixed>                  $metadata
     */
    private function persistOriginalMediaMeta(Message $message, array $classification, array $metadata = []): void
    {
        if ('mediamaker' !== ($classification['topic'] ?? null)) {
            return;
        }

        $message->setMeta('original_topic', 'mediamaker');

        // Handler-derived media_type wins because it reflects the actual
        // pipeline that ran (synthesize/generateImage/generateVideo); the
        // classifier value is only the predicted intent.
        $mediaType = $metadata['media_type']
            ?? $classification['media_type']
            ?? null;

        if (!empty($mediaType)) {
            $message->setMeta('original_media_type', (string) $mediaType);
        }
    }

    /**
     * Build the nested aiModels payload mirroring the ChatController
     * /api/v1/chats/{id}/messages response shape.
     *
     * Issue #603: the SSE 'complete' event used to carry only flat
     * provider/model fields for the CHAT model, so the sorting-model badge
     * (and any other non-chat metadata) only appeared after a page refresh
     * triggered a fresh history fetch. By shipping the same nested shape the
     * REST endpoint returns, the frontend can populate all badges live.
     *
     * @return array{
     *   chat?: array{provider: ?string, model: ?string, model_id: ?int},
     *   sorting?: array{provider: ?string, model: ?string, model_id: ?int},
     *   audio?: array{provider: ?string, model: ?string, model_id: ?int},
     * }|null
     */
    private function buildAiModelsPayload(Message $message): ?array
    {
        $aiModels = [];

        $chatProvider = $message->getMeta('ai_chat_provider');
        $chatModel = $message->getMeta('ai_chat_model');
        $chatModelId = $message->getMeta('ai_chat_model_id');
        if ($chatProvider || $chatModel) {
            $aiModels['chat'] = [
                'provider' => $chatProvider,
                'model' => $chatModel,
                'model_id' => $this->normalizeModelId($chatModelId, 'aiModels_chat'),
            ];
        }

        $sortingProvider = $message->getMeta('ai_sorting_provider');
        $sortingModel = $message->getMeta('ai_sorting_model');
        $sortingModelId = $message->getMeta('ai_sorting_model_id');
        if ($sortingProvider || $sortingModel) {
            $aiModels['sorting'] = [
                'provider' => $sortingProvider,
                'model' => $sortingModel,
                'model_id' => $this->normalizeModelId($sortingModelId, 'aiModels_sorting'),
            ];
        }

        // Audio (TTS) model — separate from `chat` because voice-reply
        // pipes the LLM's text through an independent TTS provider
        // (e.g. Piper). Before #583 the chat model was relabelled as
        // "Audio Model" in the UI, which surfaced the wrong identifier
        // (gpt-5.4 instead of piper-multi).
        $audioProvider = $message->getMeta('ai_audio_provider');
        $audioModel = $message->getMeta('ai_audio_model');
        $audioModelId = $message->getMeta('ai_audio_model_id');
        if ($audioProvider || $audioModel) {
            $aiModels['audio'] = [
                'provider' => $audioProvider,
                'model' => $audioModel,
                'model_id' => $this->normalizeModelId($audioModelId, 'aiModels_audio'),
            ];
        }

        return [] === $aiModels ? null : $aiModels;
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
    /**
     * Persist the multi-task output files as File entities so they survive a
     * page reload — history (`ChatController::getMessages`) serializes only the
     * Message<->File relation, never the legacy BFILE/BFILEPATH columns.
     *
     * The primary file (index 0) also rides the legacy single-file channel,
     * which the frontend renders inline for image/video/audio on reload —
     * registering those again would duplicate the media (e.g. a second audio
     * player). So index 0 is only registered when the legacy channel cannot
     * render it (document and other types — issue #1055); extra files
     * (index > 0) are always registered, as before.
     *
     * @param array<int|string, mixed> $taskFiles metadata['files'] descriptors from the task-plan executor
     *
     * @return list<File>
     */
    private function persistTaskPlanFiles(array $taskFiles, int $userId): array
    {
        $entities = [];

        foreach (array_values($taskFiles) as $idx => $taskFile) {
            if (!is_array($taskFile) || empty($taskFile['path'])) {
                continue;
            }

            $type = is_string($taskFile['type'] ?? null) ? $taskFile['type'] : '';
            if (0 === $idx && in_array($type, ['image', 'video', 'audio'], true)) {
                continue;
            }

            $entity = $this->registerExistingGeneratedFile(
                $userId,
                is_string($taskFile['local_path'] ?? null) ? $taskFile['local_path'] : null,
                $type,
            );
            if ($entity instanceof File) {
                $entities[] = $entity;
            }
        }

        return $entities;
    }

    /**
     * Register a File row for an already-on-disk generated file (multi-task
     * extra output, Sprint 3b). The media/TTS runners save bytes to the user's
     * upload path; this only records the DB row so the file shows in history and
     * is access-controlled. Best-effort — never throws.
     */
    private function registerExistingGeneratedFile(int $userId, ?string $relativePath, string $type): ?File
    {
        if (null === $relativePath || '' === $relativePath) {
            return null;
        }

        try {
            // Issue #1170: a document-generation node persists its File via
            // ChatHandler::storeGeneratedFile() (with the clean display name)
            // BEFORE this runs. Re-registering the same on-disk file here would
            // create a second File row pointing at the identical path, so the
            // file shows up twice on the Files page. Reuse the existing row
            // instead — it already carries the nicer display name.
            $existing = $this->em->getRepository(File::class)->findOneBy([
                'userId' => $userId,
                'filePath' => $relativePath,
            ]);
            if ($existing instanceof File) {
                return $existing;
            }

            $absolutePath = $this->uploadDir.'/'.$relativePath;
            $fileSize = is_file($absolutePath) ? (filesize($absolutePath) ?: 0) : 0;
            $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

            $file = new File();
            $file->setUserId($userId);
            $file->setFilePath($relativePath);
            $file->setFileType('' !== $type ? $type : $extension);
            $file->setFileName(basename($relativePath));
            $file->setFileSize($fileSize);
            $file->setFileMime($this->getMimeTypeForExtension($extension));
            $file->setStatus('generated');
            // Issue #1190: mark provenance so generated files are distinguishable
            // from uploads (default 'web_upload') and can be targeted for repair.
            $file->setSource('generated');

            $this->em->persist($file);
            $this->em->flush();

            return $file;
        } catch (\Throwable $e) {
            $this->logger->warning('StreamController: failed to register multi-task file', [
                'path' => $relativePath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function storeGeneratedFileInStream(array $fileData, Message $message): ?File
    {
        $userId = $message->getUserId();
        $filename = $fileData['filename'];
        $content = $fileData['content'];
        $extension = $fileData['extension'];

        // Issue #1196: never persist a generated document from whitespace-only
        // content — it would create a DB row plus a blank file with no usable
        // body. Bail early so the caller can surface a real failure instead.
        if ('' === trim((string) $content)) {
            $this->logger->warning('StreamController: refusing to store generated file with empty content', [
                'filename' => $filename,
                'extension' => $extension,
            ]);

            return null;
        }

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
            $userBase = $this->userUploadPathBuilder->buildUserBaseRelativePath($userId);
            $relativePath = $userBase.'/'.$year.'/'.$month.'/'.$finalFilename;
            $absolutePath = $this->uploadDir.'/'.$relativePath;

            // Create directory if not exists
            $dir = dirname($absolutePath);
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true)) {
                    $this->logger->error('StreamController: Failed to create directory', ['dir' => $dir]);

                    return null;
                }
            }

            // Write file content (real OOXML for docx/xlsx/pptx, text otherwise)
            try {
                $this->documentGenerator->write($content, $extension, $absolutePath);
            } catch (\Throwable $e) {
                $this->logger->error('StreamController: Failed to write file', [
                    'path' => $absolutePath,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }

            $fileSize = filesize($absolutePath);
            if (false === $fileSize) {
                $this->logger->error('StreamController: Failed to read generated file size', ['path' => $absolutePath]);

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
            $file->setFileSize($fileSize);
            $file->setFileMime($mimeType);
            // Persist the source content (Markdown/CSV/text) the document was
            // built from — even for binary office formats. It is the document's
            // text for search and, crucially, lets a later edit transform the
            // exact current content instead of re-deriving it.
            $file->setFileText($content);
            $file->setStatus('generated');
            // Issue #1190: mark provenance so generated files are distinguishable
            // from uploads (default 'web_upload') and can be regenerated from
            // BFILETEXT on download when the on-disk binary goes missing.
            $file->setSource('generated');

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
            $chat = $this->em->getRepository(Chat::class)->find((int) $chatId);
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
            // CRITICAL: Ensure response timestamp is AFTER incoming message timestamp
            // to guarantee correct chronological order (fix race condition when switching chats quickly)
            $currentTimestamp = time();
            $incomingTimestamp = $incomingMessage->getUnixTimestamp();

            // Only adjust timestamp if it would cause wrong order (response before or same time as input)
            if ($currentTimestamp <= $incomingTimestamp) {
                $responseTimestamp = $incomingTimestamp + 1;
            } else {
                $responseTimestamp = $currentTimestamp;
            }

            $outgoingMessage = new Message();
            $outgoingMessage->setUserId($user->getId());
            $outgoingMessage->setChat($chat);
            $outgoingMessage->setTrackingId($trackId);
            $outgoingMessage->setProviderIndex($incomingMessage->getProviderIndex());
            $outgoingMessage->setUnixTimestamp($responseTimestamp);
            $outgoingMessage->setDateTime(date('YmdHis', $responseTimestamp));
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

            // Issue #1146: a cancelled chat response still consumed provider
            // tokens for whatever was streamed before the user hit Stop. The
            // normal recordUsage(MESSAGES) call in the streaming callback is
            // bypassed on cancel, so record a best-effort entry here from the
            // partial content. Without a model id the cost resolves to 0 but the
            // token/usage counters still move, so the turn is never completely
            // free. Media (IMAGES/VIDEOS/AUDIOS) is billed separately and more
            // precisely inside MediaGenerationHandler.
            // Truly best-effort: the cancelled message is already persisted above,
            // so a failure to record usage must NOT turn a successful cancellation
            // into an HTTP 500. Swallow any error here (it is only accounting) and
            // let the endpoint return success.
            try {
                $cancelledModelId = $incomingMessage->getMeta('ai_chat_model_id');
                $this->rateLimitService->recordUsage($user, 'MESSAGES', [
                    'provider' => $chatProvider ?? 'unknown',
                    'model' => $chatModel ?? 'unknown',
                    'model_id' => null !== $cancelledModelId && '' !== $cancelledModelId ? (int) $cancelledModelId : null,
                    'chat_id' => (int) $chatId,
                    'source' => 'WEB',
                    'response_text' => is_string($content) ? $content : '',
                    'input_text' => $incomingMessage->getText(),
                    'status' => 'cancelled',
                ]);
            } catch (\Throwable $usageError) {
                $this->logger->warning('Failed to record usage for cancelled chat turn', [
                    'user_id' => $user->getId(),
                    'track_id' => $trackId,
                    'error' => $usageError->getMessage(),
                ]);
            }

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

        // Flag the whole turn as cancelled so a blocking media poll (Higgsfield
        // video) running on another worker sees it and aborts the provider call.
        // EventSource.close() alone can't interrupt a worker that is busy polling
        // and produces no output to trip connection_aborted().
        if (null !== $trackId && '' !== (string) $trackId) {
            $this->cancellationStore->requestCancel((string) $trackId);
        }

        return $this->json([
            'success' => true,
            'message' => 'Stream stop requested',
        ]);
    }

    /**
     * Cancel a single in-flight multitask media node (per-card Stop button)
     * without stopping the rest of the turn.
     */
    #[Route('/cancel-node', name: 'cancel_node', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/messages/cancel-node',
        summary: 'Cancel a single multitask media node',
        description: 'Flags one running media-generation step (by track id + node id) for cancellation. The streaming worker aborts that node and tells the provider to cancel, while sibling steps keep running.',
        security: [['Bearer' => []]],
        tags: ['Messages']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['trackId', 'nodeId'],
            properties: [
                new OA\Property(property: 'trackId', type: 'string', example: '1234567890'),
                new OA\Property(property: 'nodeId', type: 'string', example: 'n2'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Cancellation requested',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
            ]
        )
    )]
    public function cancelNode(Request $request, #[CurrentUser] ?User $user): Response
    {
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $trackId = is_array($data) && isset($data['trackId']) && is_scalar($data['trackId']) ? (string) $data['trackId'] : '';
        $nodeId = is_array($data) && isset($data['nodeId']) && is_scalar($data['nodeId']) ? (string) $data['nodeId'] : '';

        if ('' === $trackId || '' === $nodeId) {
            return $this->json(['error' => 'trackId and nodeId are required'], Response::HTTP_BAD_REQUEST);
        }

        $this->cancellationStore->requestCancel($trackId, $nodeId);

        $this->logger->info('Cancel media node requested', [
            'user_id' => $user->getId(),
            'track_id' => $trackId,
            'node_id' => $nodeId,
        ]);

        return $this->json(['success' => true]);
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
        if (!$user instanceof User) {
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
