<?php

namespace App\Controller;

use App\Entity\Chat;
use App\Entity\File;
use App\Entity\Message;
use App\Entity\WidgetSession;
use App\Repository\ChatRepository;
use App\Repository\FileRepository;
use App\Repository\MessageRepository;
use App\Service\Branding\BrandingService;
use App\Service\Chat\Run\ChatRunService;
use App\Service\File\FileProcessor;
use App\Service\File\FileStorageService;
use App\Service\File\VectorizationService;
use App\Service\RAG\VectorSearchService;
use App\Service\RateLimitService;
use App\Service\Widget\Turn\WidgetConversation;
use App\Service\Widget\Turn\WidgetReply;
use App\Service\Widget\Turn\WidgetTurnGate;
use App\Service\Widget\WidgetDomainGuard;
use App\Service\Widget\WidgetHandoff;
use App\Service\Widget\WidgetRequestRefused;
use App\Service\Widget\WidgetService;
use App\Service\Widget\WidgetSessionResolver;
use App\Service\Widget\WidgetSessionService;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
        private RateLimitService $rateLimitService,
        private FileStorageService $storageService,
        private FileProcessor $fileProcessor,
        private VectorizationService $vectorizationService,
        private ChatRepository $chatRepository,
        private MessageRepository $messageRepository,
        private FileRepository $fileRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private BrandingService $brandingService,
        private ChatRunService $chatRunService,
        private string $uploadDir,
        private VectorSearchService $vectorSearchService,
        private WidgetTurnGate $turnGate,
        private WidgetConversation $conversation,
        private WidgetReply $reply,
        private WidgetDomainGuard $domainGuard,
        private WidgetSessionResolver $sessionResolver,
        private WidgetHandoff $handoff,
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
                new OA\Property(
                    property: 'branding',
                    type: 'object',
                    description: 'White-label attribution (Epic 4.5) from the global branding config. Lets a hoster hide or re-attribute the widget "Powered by" footer.',
                    properties: [
                        new OA\Property(property: 'showPoweredBy', type: 'boolean', example: true),
                        new OA\Property(property: 'poweredByLabel', type: 'string', example: 'Synaplan'),
                        new OA\Property(property: 'poweredByUrl', type: 'string', example: 'https://www.synaplan.com'),
                    ]
                ),
                new OA\Property(
                    property: 'realtime',
                    type: 'object',
                    description: 'Realtime / Centrifugo settings consumed by the embedded widget. The cross-origin widget cannot read /api/v1/config/runtime, so we surface the relevant subset here.',
                    properties: [
                        new OA\Property(
                            property: 'enabled',
                            type: 'boolean',
                            example: true,
                            description: 'Master kill-switch. When false, the widget makes no WebSocket connection.'
                        ),
                        new OA\Property(
                            property: 'wsUrl',
                            type: 'string',
                            example: 'wss://app.example.com/connection/websocket',
                            description: 'Browser-facing WebSocket endpoint. Empty string means "derive from apiUrl" (Caddy reverse-proxies /connection/websocket to Centrifugo).'
                        ),
                    ]
                ),
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

        // MOBILE-APP SEAM (Epic 4.5): white-label attribution. The cross-origin
        // widget cannot read /api/v1/config/runtime, so we surface the global
        // branding's powered-by subset here (hide / re-attribute flag). Defaults
        // keep the historical "powered by synaplan" footer.
        $branding = $this->brandingService->getBranding();

        return $this->json([
            'success' => true,
            'widgetId' => $widget->getWidgetId(),
            'name' => $widget->getName(),
            'config' => self::buildPublicConfig($config),
            'isActive' => true,
            'branding' => [
                'showPoweredBy' => $branding['showPoweredBy'],
                'poweredByLabel' => $branding['poweredByLabel'],
                'poweredByUrl' => $branding['poweredByUrl'],
            ],
            'realtime' => [
                // REALTIME_ENABLED is the global kill-switch shared with the
                // operator UI (see ConfigController::getRuntimeConfig). Empty
                // wsUrl tells the widget to derive the endpoint from apiUrl,
                // which is the right default for both same-origin and
                // cross-origin embeds. Default OFF when unset so a bare
                // deployment without a Centrifugo gateway doesn't make the
                // embedded widget loop on connection errors.
                'enabled' => 'true' === ($_ENV['REALTIME_ENABLED'] ?? 'false'),
                'wsUrl' => (string) ($_ENV['REALTIME_PUBLIC_WS_URL'] ?? ''),
            ],
        ]);
    }

    /**
     * Request a human takeover: pings the operator's Slack channel and flips
     * the session into WAITING so the dashboard surfaces it for pickup.
     *
     * PUBLIC endpoint — no authentication required. Idempotent at the
     * session-mode level: a second click while the session is already
     * WAITING or HUMAN returns 200 with `notified: false` instead of paging
     * Slack again.
     */
    #[Route('/{widgetId}/human-handoff', name: 'human_handoff', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/widget/{widgetId}/human-handoff',
        summary: 'Request a human operator (posts to widget owner Slack + sets session to WAITING)',
        tags: ['Widget (Public)']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['sessionId'],
            properties: [
                new OA\Property(property: 'sessionId', type: 'string', example: 'sess_xyz123...'),
                new OA\Property(property: 'lastMessage', type: 'string', nullable: true, description: 'Optional last visitor message snippet for context (server falls back to its own last-message preview)'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Handoff request accepted',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'notified', type: 'boolean', example: true, description: 'true when Slack accepted the payload, false when delivery failed (session is still moved to WAITING)'),
                new OA\Property(property: 'mode', type: 'string', example: 'waiting'),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Missing sessionId / handoff disabled for this widget')]
    #[OA\Response(response: 404, description: 'Widget or session not found')]
    public function humanHandoff(string $widgetId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || empty($data['sessionId'])) {
            return $this->json(['error' => 'Missing required field: sessionId'], Response::HTTP_BAD_REQUEST);
        }
        $sessionId = (string) $data['sessionId'];

        $widget = $this->widgetService->getWidgetById($widgetId);
        if (!$widget) {
            return $this->json(['error' => 'Widget not found'], Response::HTTP_NOT_FOUND);
        }

        $config = $widget->getConfig();
        if ($domainError = $this->ensureDomainAllowed($config, $request, $widget->getOwnerId())) {
            return $domainError;
        }

        $webhookUrl = (string) ($config['slackWebhookUrl'] ?? '');
        $buttonEnabled = (bool) ($config['humanHandoffButtonEnabled'] ?? true);
        if ('' === $webhookUrl || !$buttonEnabled) {
            return $this->json([
                'error' => 'Human handoff is not enabled for this widget',
                'reason' => 'handoff_disabled',
            ], Response::HTTP_BAD_REQUEST);
        }

        $session = $this->em->getRepository(WidgetSession::class)->findByWidgetAndSession($widgetId, $sessionId);
        if (!$session) {
            return $this->json(['error' => 'Session not found'], Response::HTTP_NOT_FOUND);
        }

        // Idempotence: once the session is already waiting for / talking to a
        // human, swallow repeated button clicks instead of paging Slack
        // again. The UI hides the button in those modes anyway, but a
        // double-click race or a stale tab can still get through.
        if (in_array($session->getMode(), [WidgetSession::MODE_WAITING, WidgetSession::MODE_HUMAN], true)) {
            return $this->json([
                'success' => true,
                'notified' => false,
                'mode' => $session->getMode(),
                'message' => 'Handoff already pending or active.',
            ], Response::HTTP_OK);
        }

        $lastMessage = isset($data['lastMessage']) && is_string($data['lastMessage'])
            ? trim($data['lastMessage'])
            : ($session->getLastMessagePreview() ?? '');

        $notified = $this->handoff->page($widget, $session, $webhookUrl, '' !== $lastMessage ? $lastMessage : null, 'manual button click');

        return $this->json([
            'success' => true,
            'notified' => $notified,
            'mode' => $session->getMode(),
        ]);
    }

    /**
     * Return only runtime-safe config keys needed by the widget frontend.
     * Sensitive fields (API tokens, internal flags) are excluded.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function buildPublicConfig(array $config): array
    {
        static $allowed = [
            'position',
            'primaryColor',
            'iconColor',
            'buttonIcon',
            'buttonIconUrl',
            'defaultTheme',
            'autoOpen',
            'autoMessage',
            'messageLimit',
            'maxFileSize',
            'allowFileUpload',
            'fileUploadLimit',
            'fullscreenMode',
            'allowFullscreen',
            'hideButton',
            'privacyPolicyUrl',
            'detectTheme',
            'sessionMode',
            'widgetSubtitle',
            'aiAssistantName',
        ];

        $public = \array_intersect_key($config, \array_flip($allowed));

        // Surface only the derived enabled-flag, never the raw Slack webhook
        // URL or the trigger phrases. Both are server-side secrets/policy and
        // would otherwise leak to every page that embeds the widget.
        $hasSlackWebhook = isset($config['slackWebhookUrl'])
            && \is_string($config['slackWebhookUrl'])
            && '' !== $config['slackWebhookUrl'];
        $buttonEnabled = (bool) ($config['humanHandoffButtonEnabled'] ?? true);
        $public['humanHandoffEnabled'] = $hasSlackWebhook && $buttonEnabled;

        return $public;
    }

    /**
     * One visitor message, as a widget turn in three stages: the gate admits
     * or refuses it, the conversation stores it, and the reply streams the AI
     * answer, unless a person runs the session.
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
        try {
            $turn = $this->turnGate->admit($widgetId, $request);
        } catch (WidgetRequestRefused $refused) {
            return $this->json($refused->payload, $refused->httpStatus);
        }

        try {
            $stored = $this->conversation->store($turn);
            if ($turn->heldForOperator) {
                return $this->json($this->conversation->awaitOperator($turn, $stored));
            }

            return $this->reply->stream($turn, $stored);
        } catch (\Exception $e) {
            return $this->json($this->reply->failure($turn, $e), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Passage for a source this widget conversation already cited.
     * The visitor session is required. A chunk id that was not cited here is not readable.
     */
    #[Route('/{widgetId}/rag/chunks/{chunkId}', name: 'rag_chunk', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/widget/{widgetId}/rag/chunks/{chunkId}',
        summary: 'Load a cited knowledge passage for this widget session',
        tags: ['Widget (Public)']
    )]
    #[OA\Parameter(name: 'widgetId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'chunkId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Passage text')]
    #[OA\Response(response: 404, description: 'Session or passage not found')]
    public function ragChunk(string $widgetId, string $chunkId, Request $request): JsonResponse
    {
        $sessionId = $request->headers->get('X-Widget-Session');
        if (!is_string($sessionId) || '' === $sessionId) {
            return $this->json(['error' => 'X-Widget-Session header required'], Response::HTTP_BAD_REQUEST);
        }
        $widget = $this->widgetService->getWidgetById($widgetId);
        if (!$widget || !$this->widgetService->isWidgetActive($widget)) {
            return $this->json(['error' => 'Widget not found'], Response::HTTP_NOT_FOUND);
        }
        if ($domainError = $this->ensureDomainAllowed($widget->getConfig(), $request, $widget->getOwnerId())) {
            return $domainError;
        }
        $session = $this->sessionService->getSession($widgetId, $sessionId);
        $chatId = $session?->getChatId();
        if (!$session || null === $chatId || $chatId <= 0) {
            return $this->json(['success' => false, 'error' => 'Passage not found'], Response::HTTP_NOT_FOUND);
        }
        $chat = $this->chatRepository->find($chatId);
        if (!$chat || $chat->getUserId() !== $widget->getOwnerId() || !$this->chatCitesChunk($chat, $chunkId)) {
            return $this->json(['success' => false, 'error' => 'Passage not found'], Response::HTTP_NOT_FOUND);
        }
        $passage = $this->vectorSearchService->passage($widget->getOwnerId(), $chunkId);
        if (null === $passage) {
            return $this->json(['success' => false, 'error' => 'Passage not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['success' => true] + $passage);
    }

    private function chatCitesChunk(Chat $chat, string $chunkId): bool
    {
        $chatId = $chat->getId();
        if (null === $chatId || '' === $chunkId) {
            return false;
        }
        foreach ($this->messageRepository->findChatHistory($chat->getUserId(), $chatId, 50, 200000) as $message) {
            $raw = $message->getMeta('rag_sources');
            if (!is_string($raw) || !str_contains($raw, $chunkId)) {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $row) {
                if (is_array($row) && (string) ($row['chunkId'] ?? '') === $chunkId) {
                    return true;
                }
            }
        }

        return false;
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

        // Resolve session with test/internal mode handling
        $widgetSession = $this->resolveSessionWithMode($request, $widget, $sessionId);

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

                // Widget uploads are analysed immediately (extract + vectorize
                // into WIDGET:<id> RAG), so bill FILE_ANALYSIS once here.
                // recordFileAnalysisOnce dedupes on file_id if a later chat
                // turn also analyses the same row. Authenticated /upload-file
                // still defers billing because those files are only staged
                // until send (issue #887 / #1912).
                if ($owner && isset($result['file']['id'])) {
                    $this->rateLimitService->recordFileAnalysisOnce($owner, (int) $result['file']['id'], [
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
            }

            return $this->json($result, Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('Widget file upload failed', [
                'widget_id' => $widgetId,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'error' => 'File upload failed',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Process uploaded file for widget session
     * Same workflow as FileController but for anonymous widget users.
     */
    private function processWidgetFile($uploadedFile, $widget, $widgetSession): array
    {
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
        // HEIC uploads are stored as JPEG; use the final stored extension/name.
        $fileExtension = strtolower($storageResult['extension'] ?? $uploadedFile->getClientOriginalExtension());
        $displayName = $storageResult['display_name'] ?? $uploadedFile->getClientOriginalName();

        // Create File entity with BUSERID=0 and BUSERSESSIONID=session_id
        $file = new File();
        $file->setUserId(0); // Anonymous widget user
        $file->setUserSessionId($widgetSession->getId()); // Link to widget session
        $file->setFilePath($relativePath);
        $file->setFileName($displayName);
        $file->setFileSize($storageResult['size']);
        $file->setFileMime($storageResult['mime']);
        $file->setFileType($this->getFileTypeString($fileExtension));
        $file->setGroupKey("WIDGET:{$widget->getWidgetId()}");

        $this->em->persist($file);
        $this->em->flush();

        $result = [
            'success' => true,
            'file' => [
                'id' => $file->getId(),
                'filename' => $displayName,
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
                'error' => 'Text extraction failed',
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
    #[OA\Parameter(
        name: 'sessionId',
        in: 'query',
        required: true,
        description: 'Server-issued widget session id.',
        schema: new OA\Schema(type: 'string')
    )]
    #[OA\Response(
        response: 200,
        description: 'Session history, plus a still-running turn when there is one',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'chatId', type: 'integer', nullable: true),
                new OA\Property(property: 'messages', type: 'array', items: new OA\Items(type: 'object')),
                new OA\Property(property: 'session', type: 'object'),
                new OA\Property(
                    property: 'activeRun',
                    description: 'Present when a turn in this session is still generating. The turn survives a client disconnect and buffers its Server-Sent Events, so a reloaded widget can paint `partialText` and re-attach via GET /api/v1/messages/stream/attach.',
                    type: 'object',
                    nullable: true,
                    properties: [
                        new OA\Property(property: 'runId', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'trackId', type: 'string'),
                        new OA\Property(property: 'lastSeq', type: 'integer'),
                        new OA\Property(property: 'partialText', type: 'string'),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'sessionId missing')]
    #[OA\Response(response: 404, description: 'Widget not found')]
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
                    'mode' => 'ai',
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
                    'mode' => $session->getMode(),
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
                    'mode' => $session->getMode(),
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
                        'fileSize' => $file->getFileSize(),
                        'fileMime' => $file->getFileMime(),
                    ];
                }
            }

            // Determine sender type based on direction and provider
            $providerIndex = $message->getProviderIndex();
            if ('IN' === $message->getDirection()) {
                $sender = 'user';
            } elseif ('SYSTEM' === $providerIndex) {
                $sender = 'system';
            } elseif ('HUMAN_OPERATOR' === $providerIndex) {
                $sender = 'human';
            } else {
                $sender = 'ai';
            }

            $ragSources = null;
            $ragRaw = $message->getMeta('rag_sources');
            if (is_string($ragRaw) && '' !== $ragRaw) {
                $decoded = json_decode($ragRaw, true);
                if (is_array($decoded) && [] !== $decoded) {
                    $ragSources = $decoded;
                }
            }

            return [
                'id' => $message->getId(),
                'direction' => $message->getDirection(),
                'text' => $message->getText(),
                'timestamp' => $message->getUnixTimestamp(),
                'messageType' => $message->getMessageType(),
                'sender' => $sender,
                'files' => $filesData,
                'ragSources' => $ragSources,
                'metadata' => [
                    'topic' => $message->getTopic(),
                    'language' => $message->getLanguage(),
                ],
            ];
        }, $messages);

        $payload = [
            'success' => true,
            'chatId' => $chat->getId(),
            'messages' => $history,
            'session' => [
                'sessionId' => $session->getSessionId(),
                'messageCount' => $session->getMessageCount(),
                'fileCount' => $session->getFileCount(),
                'lastMessage' => $session->getLastMessage() ?: null,
                'mode' => $session->getMode(),
            ],
        ];

        // A turn still generating for this session: the visitor reloaded the
        // host page mid-answer, so hand them the text so far plus the run id to
        // re-attach to (GET /api/v1/messages/stream/attach).
        $activeRun = $this->chatRunService->describeActiveForChat(
            (int) $chat->getId(),
            ChatRunService::ownerKeyForWidget($session->getSessionId()),
        );
        if (null !== $activeRun) {
            $payload['activeRun'] = $activeRun;
        }

        return $this->json($payload);
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

    /**
     * @param array<string, mixed> $config
     *
     * @see WidgetDomainGuard
     */
    private function ensureDomainAllowed(array $config, Request $request, ?int $widgetOwnerId = null): ?JsonResponse
    {
        try {
            $this->domainGuard->check($config, $request, $widgetOwnerId);
        } catch (WidgetRequestRefused $refused) {
            return $this->json($refused->payload, $refused->httpStatus);
        }

        return null;
    }

    /**
     * @see WidgetSessionResolver
     */
    private function resolveSessionWithMode(Request $request, \App\Entity\Widget $widget, string $sessionId): WidgetSession
    {
        return $this->sessionResolver->resolve($request, $widget, $sessionId);
    }

    /**
     * @deprecated Visitor typing previews now stream over the
     *             `widgettyping:*` Centrifugo channel via direct
     *             client-publish from the embedded widget. This endpoint
     *             is retained for ONE release as a no-op so that
     *             browser-cached old `widget.js` bundles do not start
     *             hitting 404s after the cutover. Will be removed in the
     *             release that follows.
     */
    #[Route('/{widgetId}/typing', name: 'typing', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/widget/{widgetId}/typing',
        summary: '[DEPRECATED] Send typing indicator (public)',
        description: 'Deprecated — visitor typing previews now stream over the `widgettyping:*` Centrifugo channel via direct client-publish. Retained for one release as a no-op for backward compatibility with cached widget bundles.',
        deprecated: true,
        tags: ['Widget (Public)']
    )]
    #[OA\Response(response: 200, description: 'Accepted (deprecated, no-op)')]
    public function typing(string $widgetId): JsonResponse
    {
        // debug, not info: the endpoint is unauthenticated, so an attacker
        // could otherwise flood production logs with arbitrary widget_id
        // strings at zero cost.
        $this->logger->debug('Deprecated visitor typing endpoint hit (no-op)', [
            'widget_id' => $widgetId,
        ]);

        // Returning 200 lets stale widget bundles silently degrade until
        // the customer reloads the page and picks up the new bundle that
        // publishes typing frames directly to Centrifugo.
        return $this->json([
            'success' => true,
            'deprecated' => true,
            'replacement' => 'widgettyping channel via direct client-publish',
        ]);
    }
}
