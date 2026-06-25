<?php

namespace App\Controller;

use App\AI\Interface\ProviderMetadataInterface;
use App\AI\Service\ProviderRegistry;
use App\Entity\Config;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Service\BillingService;
use App\Service\Branding\BrandingService;
use App\Service\Client\ClientContextResolver;
use App\Service\Client\MobileVersionService;
use App\Service\Embedding\EmbeddingMetadataService;
use App\Service\Embedding\EmbeddingModelChangeGuard;
use App\Service\Embedding\Exception\PremiumRequiredException;
use App\Service\Infrastructure\RedisService;
use App\Service\MarketingNews\MarketingNewsConfig;
use App\Service\ModelConfigService;
use App\Service\Plugin\PluginManager;
use App\Service\Search\BraveSearchService;
use App\Service\UserMemoryService;
use App\Service\WhisperService;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/config', name: 'api_config_')]
#[OA\Tag(name: 'Configuration')]
class ConfigController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ConfigRepository $configRepository,
        private ModelRepository $modelRepository,
        private ProviderRegistry $providerRegistry,
        private BraveSearchService $braveSearchService,
        private WhisperService $whisperService,
        private PluginManager $pluginManager,
        private BillingService $billingService,
        private UserMemoryService $memoryService,
        private EmbeddingModelChangeGuard $embeddingChangeGuard,
        private EmbeddingMetadataService $embeddingMetadata,
        private ModelConfigService $modelConfigService,
        private RedisService $redisService,
        private ClientContextResolver $clientContextResolver,
        private BrandingService $brandingService,
        private MobileVersionService $mobileVersionService,
        private MarketingNewsConfig $marketingNewsConfig,
        #[Autowire('%env(string:default::QDRANT_URL)%')]
        private readonly string $qdrantUrl,
    ) {
    }

    /**
     * Quick Qdrant availability check (lightweight, no full status)
     * Frontend calls this asynchronously after app load to check if Qdrant is reachable.
     */
    #[Route('/memory-service/check', name: 'memory_service_check', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/config/memory-service/check',
        summary: 'Check Qdrant availability',
        description: 'Quick check if Qdrant vector database is reachable (called asynchronously)',
        tags: ['Configuration']
    )]
    #[OA\Response(
        response: 200,
        description: 'Qdrant availability status',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'available', type: 'boolean', example: true),
                new OA\Property(property: 'configured', type: 'boolean', example: true),
            ]
        )
    )]
    public function checkMemoryService(): JsonResponse
    {
        $configured = '' !== trim($this->qdrantUrl);
        $available = $configured && $this->memoryService->isAvailable();

        return $this->json([
            'available' => $available,
            'configured' => $configured,
        ]);
    }

    /**
     * Get public runtime configuration (no auth required)
     * Used by frontend to get reCAPTCHA site key and other public settings.
     */
    #[Route('/runtime', name: 'runtime_config', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/config/runtime',
        summary: 'Get public runtime configuration',
        description: 'Returns public configuration like reCAPTCHA site key, feature flags (no authentication required)',
        tags: ['Configuration']
    )]
    #[OA\Response(
        response: 200,
        description: 'Public runtime configuration',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'billing',
                    type: 'object',
                    description: 'Billing/subscription status (false for open-source deployments)',
                    properties: [
                        new OA\Property(property: 'enabled', type: 'boolean', example: false),
                    ]
                ),
                new OA\Property(
                    property: 'recaptcha',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'enabled', type: 'boolean', example: true),
                        new OA\Property(property: 'siteKey', type: 'string', example: '6LcXXXXXXXXXXXXXXXXXXXXXXXXXXXXX'),
                    ]
                ),
                new OA\Property(
                    property: 'features',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'help', type: 'boolean', example: true, description: 'Enable help system'),
                        new OA\Property(property: 'memoryService', type: 'boolean', example: true, description: 'Qdrant vector database availability'),
                    ]
                ),
                new OA\Property(
                    property: 'branding',
                    type: 'object',
                    description: 'White-label branding (Epic 4). Defaults reproduce the historical Synaplan look. Public — no auth required.',
                    properties: [
                        new OA\Property(property: 'name', type: 'string', example: 'Synaplan', description: 'Displayed brand/product name'),
                        new OA\Property(property: 'tagline', type: 'string', example: '', description: 'Optional short brand description/tagline'),
                        new OA\Property(property: 'primaryColor', type: 'string', example: '#003fc7', description: 'Accent color injected into the --brand CSS variables at runtime'),
                        new OA\Property(property: 'secondaryColor', type: 'string', example: '', description: 'Optional secondary color; empty string keeps the default palette'),
                        new OA\Property(property: 'accentColor', type: 'string', example: '', description: 'Optional accent color; empty string keeps the default palette'),
                        new OA\Property(property: 'fontFamily', type: 'string', example: '', description: 'Body font-family stack; empty string keeps the default font'),
                        new OA\Property(property: 'headingFontFamily', type: 'string', example: '', description: 'Heading font-family stack; empty string falls back to fontFamily/default'),
                        new OA\Property(property: 'fontUrl', type: 'string', example: '', description: 'Optional web-font stylesheet URL; must be CSP-allowed. Empty string = no external font'),
                        new OA\Property(property: 'logoUrl', type: 'string', example: '', description: 'Light-mode logo URL; empty string falls back to the bundled asset'),
                        new OA\Property(property: 'logoDarkUrl', type: 'string', example: '', description: 'Dark-mode logo URL; empty string falls back to the bundled asset'),
                        new OA\Property(property: 'iconUrl', type: 'string', example: '', description: 'Brand icon/favicon URL; empty string falls back to the bundled asset'),
                        new OA\Property(property: 'homepageUrl', type: 'string', example: 'https://www.synaplan.com', description: 'Brand homepage link used in auth/footer surfaces'),
                        new OA\Property(property: 'privacyUrl', type: 'string', example: 'https://www.synaplan.com/privacy-policy', description: 'Privacy-policy link (reachable in-app + store metadata; store-policy mandatory)'),
                        new OA\Property(property: 'termsUrl', type: 'string', example: 'https://www.synaplan.com/terms', description: 'Terms-of-use link (reachable in-app + store metadata)'),
                        new OA\Property(property: 'accountDeletionUrl', type: 'string', example: '', description: 'Account-deletion link (Google Play store policy). Empty string lets the app fall back to its own public /account-deletion page'),
                        new OA\Property(property: 'landingPage', type: 'string', example: '', description: 'Logged-out landing: route name or free-form path (starts with "/"); empty string keeps the default landing'),
                        new OA\Property(property: 'defaultRoute', type: 'string', example: '', description: 'Post-login default: route name or free-form path (starts with "/"); empty string keeps the default route'),
                        new OA\Property(property: 'showPoweredBy', type: 'boolean', example: true, description: 'Whether to show the "· powered by <label>" attribution'),
                        new OA\Property(property: 'poweredByLabel', type: 'string', example: 'Synaplan', description: 'Attribution label (the platform being credited)'),
                        new OA\Property(property: 'poweredByUrl', type: 'string', example: 'https://www.synaplan.com', description: 'Attribution link target'),
                    ]
                ),
                new OA\Property(
                    property: 'speech',
                    type: 'object',
                    description: 'Speech-to-text configuration',
                    properties: [
                        new OA\Property(
                            property: 'whisperEnabled',
                            type: 'boolean',
                            example: true,
                            description: 'When true, local Whisper.cpp is available for record-then-transcribe mode.'
                        ),
                        new OA\Property(
                            property: 'speechToTextAvailable',
                            type: 'boolean',
                            example: true,
                            description: 'When true, any speech-to-text method is available (local Whisper OR API models like Groq/OpenAI). Frontend should show microphone button.'
                        ),
                    ]
                ),
                new OA\Property(
                    property: 'plugins',
                    type: 'array',
                    description: 'List of installed plugins for the current user',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'name', type: 'string', example: 'hello_world'),
                            new OA\Property(property: 'version', type: 'string', example: '1.0.0'),
                            new OA\Property(property: 'description', type: 'string', example: 'A simple hello world plugin'),
                            new OA\Property(property: 'capabilities', type: 'array', items: new OA\Items(type: 'string')),
                        ]
                    )
                ),
                new OA\Property(
                    property: 'googleTag',
                    type: 'object',
                    description: 'Google Tag Manager / Google Analytics configuration',
                    properties: [
                        new OA\Property(
                            property: 'enabled',
                            type: 'boolean',
                            example: true,
                            description: 'Whether Google Tag tracking is enabled'
                        ),
                        new OA\Property(
                            property: 'tagId',
                            type: 'string',
                            example: 'G-XXXXXXXXXX',
                            description: 'Google Tag ID (GTM-XXXXXXX or G-XXXXXXXXXX)'
                        ),
                    ]
                ),
                new OA\Property(
                    property: 'build',
                    type: 'object',
                    description: 'Build and deployment information for debugging',
                    properties: [
                        new OA\Property(
                            property: 'version',
                            type: 'string',
                            example: '2.7.0',
                            description: 'Application version'
                        ),
                        new OA\Property(
                            property: 'ip',
                            type: 'string',
                            example: '10.0.0.2',
                            description: 'Internal server IP (not public)'
                        ),
                    ]
                ),
                new OA\Property(
                    property: 'realtime',
                    type: 'object',
                    description: 'Realtime / WebSocket gateway settings consumed by the frontend Centrifugo client. There is no transport fallback — when `enabled` is false the dashboard simply does not subscribe.',
                    properties: [
                        new OA\Property(
                            property: 'enabled',
                            type: 'boolean',
                            example: true,
                            description: 'Master kill-switch. When false, the dashboard skips every realtime subscription.'
                        ),
                        new OA\Property(
                            property: 'wsUrl',
                            type: 'string',
                            example: 'wss://app.example.com/connection/websocket',
                            description: 'Browser-facing WebSocket endpoint. Empty string means "use same-origin /connection/websocket" (Caddy reverse-proxies to centrifugo).'
                        ),
                    ]
                ),
                new OA\Property(
                    property: 'client',
                    type: 'object',
                    description: 'Server-confirmed identity of the calling client, derived from the User-Agent. Lets the frontend switch behaviour server-truthfully (e.g. payment channel gating) instead of trusting only the client-side Capacitor.isNativePlatform() flag. Identity hint only — never an auth control.',
                    properties: [
                        new OA\Property(
                            property: 'isMobileApp',
                            type: 'boolean',
                            example: false,
                            description: 'True when the request carries the official "Synaplan Mobile Vx.x" User-Agent token.'
                        ),
                        new OA\Property(
                            property: 'appVersion',
                            type: 'string',
                            nullable: true,
                            example: '4.0',
                            description: 'Parsed app version (major.minor[.patch]) from the User-Agent, or null for web clients.'
                        ),
                        new OA\Property(
                            property: 'platform',
                            type: 'string',
                            enum: ['web', 'mobile'],
                            example: 'web',
                            description: 'Resolved client platform.'
                        ),
                    ]
                ),
                new OA\Property(
                    property: 'mobile',
                    type: 'object',
                    description: 'Forced-update gate (Epic 8.2). The operator configures a minimum supported app version; the server compares it against the parsed UA version. Empty minVersion means no gate.',
                    properties: [
                        new OA\Property(
                            property: 'minVersion',
                            type: 'string',
                            example: '4.0',
                            description: 'Minimum supported app version, or empty string when no gate is configured.'
                        ),
                        new OA\Property(
                            property: 'updateRequired',
                            type: 'boolean',
                            example: false,
                            description: 'True when the calling mobile app is older than minVersion and must show a blocking "please update" screen.'
                        ),
                        new OA\Property(
                            property: 'iosAppUrl',
                            type: 'string',
                            example: 'https://apps.apple.com/app/id000000000',
                            description: 'App Store link for the update button (empty when unset).'
                        ),
                        new OA\Property(
                            property: 'androidAppUrl',
                            type: 'string',
                            example: 'https://play.google.com/store/apps/details?id=com.synaplan.app',
                            description: 'Play Store link for the update button (empty when unset).'
                        ),
                    ]
                ),
                new OA\Property(
                    property: 'marketingNews',
                    type: 'object',
                    description: 'Guest-landing marketing news master switch. When false, the frontend renders no news section and performs no news fetch.',
                    properties: [
                        new OA\Property(
                            property: 'enabled',
                            type: 'boolean',
                            example: false,
                            description: 'Admin-controlled master switch (off by default). Anonymous visitors only.'
                        ),
                    ]
                ),
                new OA\Property(
                    property: 'unavailableProviders',
                    type: 'array',
                    description: 'AI providers that are disabled due to missing API keys (only for authenticated users)',
                    items: new OA\Items(type: 'string', example: 'Anthropic'),
                    nullable: true
                ),
            ]
        )
    )]
    public function getRuntimeConfig(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $recaptchaEnabled = ($_ENV['RECAPTCHA_ENABLED'] ?? 'false') === 'true';
        $recaptchaSiteKey = $_ENV['RECAPTCHA_SITE_KEY'] ?? '';

        // Only send site key if reCAPTCHA is enabled and site key is configured
        $recaptchaConfig = [
            'enabled' => $recaptchaEnabled && !empty($recaptchaSiteKey) && 'your_site_key_here' !== $recaptchaSiteKey,
            'siteKey' => ($recaptchaEnabled && !empty($recaptchaSiteKey) && 'your_site_key_here' !== $recaptchaSiteKey) ? $recaptchaSiteKey : '',
        ];

        // Feature flags
        // IMPORTANT: Qdrant check is SLOW (1s timeout), so we always report true here
        // Frontend will check availability asynchronously via /api/v1/config/features/status
        $features = [
            'help' => ($_ENV['FEATURE_HELP'] ?? 'false') === 'true',
            'memoryService' => !empty($_ENV['QDRANT_URL']), // Just check if configured, not if reachable
        ];

        // Speech-to-text configuration
        // whisperEnabled: true when local Whisper.cpp is available (record-then-transcribe mode)
        // speechToTextAvailable: true when ANY transcription method is available:
        //   - Local Whisper.cpp, OR
        //   - API-based providers with valid API keys (Groq Whisper, OpenAI Whisper, etc.)
        // Frontend shows microphone button when: Web Speech API supported OR speechToTextAvailable
        $whisperLocalEnabled = ($_ENV['WHISPER_ENABLED'] ?? 'true') === 'true';
        $whisperLocalAvailable = $whisperLocalEnabled && $this->whisperService->isAvailable();

        // Check if any API-based speech-to-text providers are actually available
        // (i.e., have valid API keys configured, not just models in DB)
        $apiProvidersAvailable = count($this->providerRegistry->getAvailableProviders('speech_to_text', false)) > 0;

        $speech = [
            'whisperEnabled' => $whisperLocalAvailable,
            'speechToTextAvailable' => $whisperLocalAvailable || $apiProvidersAvailable,
        ];

        // Google Tag configuration (read from Config table, ownerId=0 for global config)
        $googleTagEnabled = '1' === $this->configRepository->getValue(0, 'GOOGLE_TAG', 'ENABLED');
        $googleTagIdRaw = $this->configRepository->getValue(0, 'GOOGLE_TAG', 'TAG_ID') ?? '';
        // Sanitize tag ID to prevent XSS - only allow alphanumeric, dash, and underscore
        // Valid formats: GTM-XXXXXXX or G-XXXXXXXXXX (where X is alphanumeric)
        $googleTagId = '';
        if (!empty($googleTagIdRaw)) {
            // Validate format: GTM- followed by alphanumeric, or G- followed by alphanumeric
            if (preg_match('/^(GTM-[A-Z0-9]+|G-[A-Z0-9]+)$/i', $googleTagIdRaw)) {
                $googleTagId = $googleTagIdRaw;
            }
        }
        $googleTagConfig = [
            'enabled' => $googleTagEnabled && !empty($googleTagId),
            'tagId' => ($googleTagEnabled && !empty($googleTagId)) ? $googleTagId : '',
        ];

        // Plugins
        $plugins = [];
        if ($user) {
            $installedPlugins = $this->pluginManager->listInstalledPlugins($user->getId());
            foreach ($installedPlugins as $plugin) {
                $plugins[] = [
                    'name' => $plugin->name,
                    'version' => $plugin->version,
                    'description' => $plugin->description,
                    'capabilities' => $plugin->capabilities,
                ];
            }
        }

        // Build information for debugging deployments (minimal: version + internal IP only).
        // Version comes from APP_VERSION, which is set by the build/release pipeline. The
        // fallback is deliberately neutral ('dev') rather than a hard-coded release number
        // — hard-coding inevitably drifts behind reality and creates misleading debug output
        // (PR #833 review).
        $buildInfo = [
            'version' => $_ENV['APP_VERSION'] ?? 'dev',
            'ip' => $this->getInternalIp(),
        ];

        $unavailableProviders = [];
        if ($user) {
            foreach ($this->providerRegistry->getUniqueProviders() as $name => $provider) {
                if ('test' === $name) {
                    continue;
                }
                if (!$provider->isAvailable()) {
                    $unavailableProviders[] = $provider->getDisplayName();
                }
            }
        }

        // Realtime / WebSocket gateway settings.
        // - enabled: master kill-switch so we can disable WS instantly without a deploy.
        // - wsUrl:   empty string ⇒ frontend uses same-origin /connection/websocket
        //            (Caddy reverse-proxies to centrifugo). Override only for setups
        //            where Centrifugo lives on a separate hostname.
        // There is no fallback transport: when realtime is disabled the dashboard
        // simply skips its subscriptions (operators still see fresh data via the
        // existing REST endpoints, just without push updates).
        // Default OFF when unset: realtime needs a configured Centrifugo gateway,
        // so a bare deployment without REALTIME_ENABLED must not advertise WS to
        // the frontend (otherwise it would loop on connection errors). The
        // official docker-compose files set REALTIME_ENABLED=true explicitly.
        $realtimeEnabled = 'true' === ($_ENV['REALTIME_ENABLED'] ?? 'false');
        $realtimeWsUrl = (string) ($_ENV['REALTIME_PUBLIC_WS_URL'] ?? '');
        $realtimeConfig = [
            'enabled' => $realtimeEnabled,
            'wsUrl' => $realtimeWsUrl,
        ];

        // Client identity (Aspect 1 / mobile app Epic 2): server-confirmed signal derived
        // from the User-Agent. The frontend uses this for server-truthful behaviour switches
        // (Epic 5 payment gating) instead of trusting only Capacitor.isNativePlatform().
        // The parsed version also feeds the forced-update gate (Epic 8).
        $client = $this->clientContextResolver->fromRequest($request);
        $clientConfig = [
            'isMobileApp' => $client->isMobileApp,
            'appVersion' => $client->appVersion,
            'platform' => $client->platform(),
        ];

        // Forced-update gate (Epic 8.2): the operator configures a minimum
        // supported app version; the server compares it against the parsed UA
        // version and tells the app to block with a "please update" screen.
        // Empty min-version ⇒ no gate (default), so web and unconfigured
        // deployments are unaffected.
        $storeUrls = $this->mobileVersionService->getStoreUrls();
        $mobileConfig = [
            'minVersion' => $this->mobileVersionService->getMinVersion(),
            'updateRequired' => $this->mobileVersionService->isUpdateRequired($client),
            'iosAppUrl' => $storeUrls['ios'],
            'androidAppUrl' => $storeUrls['android'],
        ];

        $response = [
            'billing' => [
                'enabled' => $this->billingService->isEnabled(),
            ],
            'recaptcha' => $recaptchaConfig,
            'branding' => $this->brandingService->getBranding(),
            'features' => $features,
            'speech' => $speech,
            'plugins' => $plugins,
            'googleTag' => $googleTagConfig,
            'build' => $buildInfo,
            'realtime' => $realtimeConfig,
            'client' => $clientConfig,
            'mobile' => $mobileConfig,
            'marketingNews' => [
                'enabled' => $this->marketingNewsConfig->isEnabled(),
            ],
        ];

        if ($user && !empty($unavailableProviders)) {
            $response['unavailableProviders'] = $unavailableProviders;
        }

        return $this->json($response);
    }

    /**
     * Get internal IP address (10.x.x.x range only, for debugging which server handled request).
     */
    private function getInternalIp(): string
    {
        // Check environment variable first (set by start scripts)
        $synDbHost = $_ENV['SYNDBHOST'] ?? '';
        if ('' !== $synDbHost && str_starts_with($synDbHost, '10.')) {
            return $synDbHost;
        }

        // Try to find a 10.x.x.x IP from network interfaces
        $hostname = gethostname();
        if ($hostname) {
            $ips = gethostbynamel($hostname);
            if ($ips) {
                foreach ($ips as $ip) {
                    if (str_starts_with($ip, '10.')) {
                        return $ip;
                    }
                }
            }
        }

        // Fallback: try to get from SERVER_ADDR if in 10.x range
        $serverAddr = $_SERVER['SERVER_ADDR'] ?? '';
        if (str_starts_with($serverAddr, '10.')) {
            return $serverAddr;
        }

        return 'dev';
    }

    /**
     * Get all available models (all active models for all capabilities)
     * User can choose ANY model for ANY capability (cross-capability usage).
     */
    #[Route('/models', name: 'models_list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/config/models',
        summary: 'Get all available AI models',
        description: 'Returns list of all active models grouped by capability (CHAT, IMAGE, SORT, etc.)',
        security: [['Bearer' => []]],
        tags: ['Configuration']
    )]
    #[OA\Response(
        response: 200,
        description: 'List of available models',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'models',
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'CHAT',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 53),
                                    new OA\Property(property: 'service', type: 'string', example: 'Groq'),
                                    new OA\Property(property: 'name', type: 'string', example: 'Qwen3 32B (Reasoning)'),
                                    new OA\Property(property: 'quality', type: 'integer', example: 9),
                                    new OA\Property(property: 'features', type: 'array', items: new OA\Items(type: 'string', example: 'reasoning')),
                                ]
                            )
                        ),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function getModels(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $models = $this->modelRepository->findBy(
            ['active' => 1],
            ['quality' => 'DESC', 'rating' => 'DESC']
        );

        // Build model list with tag information, excluding free models without override
        $modelList = [];
        foreach ($models as $model) {
            if ($model->isHiddenBecauseFree()) {
                continue;
            }
            $modelList[] = [
                'id' => $model->getId(),
                'service' => $model->getService(),
                'name' => $model->getName(),
                'providerId' => $model->getProviderId(),
                'description' => $model->getDescription(),
                'quality' => $model->getQuality(),
                'rating' => $model->getRating(),
                'tag' => strtoupper($model->getTag()),
                'isSystemModel' => $model->isSystemModel(),
                'features' => $model->getFeatures(),
                'priceIn' => $model->getPriceIn(),
                'priceOut' => $model->getPriceOut(),
            ];
        }

        // Group models by their appropriate capability based on tag
        // This allows proper filtering while still enabling cross-capability if needed
        $grouped = [
            'SORT' => [],
            'CHAT' => [],
            'MEM' => [],
            'VECTORIZE' => [],
            'PIC2TEXT' => [],
            'TEXT2PIC' => [],
            'PIC2PIC' => [],
            'TEXT2VID' => [],
            'IMG2VID' => [],
            'SOUND2TEXT' => [],
            'TEXT2SOUND' => [],
            'ANALYZE' => [],
        ];

        foreach ($modelList as $model) {
            $tag = $model['tag'];

            // Map model tags to capabilities
            switch ($tag) {
                case 'CHAT':
                    $grouped['CHAT'][] = $model;
                    $grouped['SORT'][] = $model; // Chat models can also be used for sorting
                    $grouped['ANALYZE'][] = $model; // Chat models can analyze
                    // NOTE: chat models are NOT bucketed into MEM — the
                    // MEM dropdown is intentionally restricted to a small
                    // curated set (BTAG=mem) to keep memory extraction
                    // fast and predictable. Operators can clone any chat
                    // model into BMODELS with BTAG=mem if they want a
                    // custom option in the picker.
                    break;
                case 'MEM':
                    // Phase 2d: dedicated memory-extraction tag. Show in the
                    // MEM dropdown only — it's not a general chat model.
                    $grouped['MEM'][] = $model;
                    break;
                case 'VECTORIZE':
                case 'EMBEDDING':
                    $grouped['VECTORIZE'][] = $model;
                    break;
                case 'VISION':
                case 'PIC2TEXT':
                    $grouped['PIC2TEXT'][] = $model;
                    break;
                case 'IMAGE':
                case 'TEXT2PIC':
                    $grouped['TEXT2PIC'][] = $model;
                    if (!empty($model['features']) && in_array('pic2pic', $model['features'], true)) {
                        $grouped['PIC2PIC'][] = $model;
                    }
                    break;
                case 'VIDEO':
                case 'TEXT2VID':
                    // Image-to-video models share the text2vid BTAG but CANNOT
                    // generate a clip from text alone — they require a reference
                    // image. Surface them ONLY in the dedicated IMG2VID slot
                    // (mirrors PIC2PIC over text2pic), never as a TEXT2VID option.
                    // Otherwise a user can pick an i2v model as their text-to-video
                    // default and every text prompt fails at the provider with
                    // "'image_url' is a required property".
                    $isImageToVideo = !empty($model['features']) && in_array('image2video', $model['features'], true);
                    if ($isImageToVideo) {
                        $grouped['IMG2VID'][] = $model;
                    } else {
                        $grouped['TEXT2VID'][] = $model;
                    }
                    break;
                case 'AUDIO':
                case 'SOUND2TEXT':
                case 'TRANSCRIPTION':
                    $grouped['SOUND2TEXT'][] = $model;
                    break;
                case 'TTS':
                case 'TEXT2SOUND':
                    $grouped['TEXT2SOUND'][] = $model;
                    break;
                default:
                    // If no specific tag, add to all capabilities (flexible)
                    foreach (array_keys($grouped) as $cap) {
                        $grouped[$cap][] = $model;
                    }
                    break;
            }
        }

        return $this->json([
            'success' => true,
            'models' => $grouped,
        ]);
    }

    /**
     * Get current default model configuration for user.
     */
    #[Route('/models/defaults', name: 'models_defaults', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/config/models/defaults',
        summary: 'Get default model configuration',
        description: 'Returns the currently configured default model IDs per capability for the authenticated user. Falls back to global defaults when no user-specific setting exists. VECTORIZE always returns the system-wide default.',
        security: [['Bearer' => []]],
        tags: ['Configuration']
    )]
    #[OA\Response(
        response: 200,
        description: 'Default model IDs per capability (null if not configured)',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'defaults',
                    type: 'object',
                    description: 'Map of capability name to model ID (null if no default is set)',
                    properties: [
                        new OA\Property(property: 'SORT', type: 'integer', nullable: true, example: 12),
                        new OA\Property(property: 'CHAT', type: 'integer', nullable: true, example: 53),
                        new OA\Property(property: 'MEM', type: 'integer', nullable: true, example: 7),
                        new OA\Property(property: 'VECTORIZE', type: 'integer', nullable: true, example: 3),
                        new OA\Property(property: 'PIC2TEXT', type: 'integer', nullable: true, example: null),
                        new OA\Property(property: 'TEXT2PIC', type: 'integer', nullable: true, example: null),
                        new OA\Property(property: 'PIC2PIC', type: 'integer', nullable: true, example: null),
                        new OA\Property(property: 'TEXT2VID', type: 'integer', nullable: true, example: null),
                        new OA\Property(property: 'IMG2VID', type: 'integer', nullable: true, example: null),
                        new OA\Property(property: 'SOUND2TEXT', type: 'integer', nullable: true, example: null),
                        new OA\Property(property: 'TEXT2SOUND', type: 'integer', nullable: true, example: null),
                        new OA\Property(property: 'ANALYZE', type: 'integer', nullable: true, example: 53),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function getDefaultModels(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $userId = $user->getId();
        $capabilities = ['SORT', 'CHAT', 'MEM', 'VECTORIZE', 'PIC2TEXT', 'TEXT2PIC', 'PIC2PIC', 'TEXT2VID', 'IMG2VID', 'SOUND2TEXT', 'TEXT2SOUND', 'ANALYZE'];

        $defaults = [];

        foreach ($capabilities as $capability) {
            // VECTORIZE is system-wide (single Qdrant collection,
            // single dimension). Skip the per-user lookup entirely so
            // the dropdown can never disagree with what the indexer
            // actually uses — see saveDefaultModels for the matching
            // write-side guard.
            if ('VECTORIZE' === $capability) {
                $config = $this->configRepository->findOneBy([
                    'ownerId' => 0,
                    'group' => 'DEFAULTMODEL',
                    'setting' => 'VECTORIZE',
                ]);
            } else {
                // Try user-specific config first
                $config = $this->configRepository->findOneBy([
                    'ownerId' => $userId,
                    'group' => 'DEFAULTMODEL',
                    'setting' => $capability,
                ]);

                // Fall back to global config
                if (!$config) {
                    $config = $this->configRepository->findOneBy([
                        'ownerId' => 0,
                        'group' => 'DEFAULTMODEL',
                        'setting' => $capability,
                    ]);
                }
            }

            if ($config) {
                $modelId = (int) $config->getValue();
                $model = $this->modelRepository->find($modelId);
                // Only return model ID if the model still exists and is active
                $defaults[$capability] = ($model && 1 === $model->getActive()) ? $modelId : null;
            } else {
                $defaults[$capability] = null;
            }
        }

        return $this->json([
            'success' => true,
            'defaults' => $defaults,
        ]);
    }

    /**
     * Save default model configuration.
     *
     * By default saves user-specific defaults (ownerId = current user).
     * With `global: true` (admin-only), saves system-wide defaults (ownerId = 0).
     */
    #[Route('/models/defaults', name: 'models_defaults_save', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/config/models/defaults',
        summary: 'Save default model configuration',
        description: 'Saves per-capability default model IDs for the authenticated user. Admins may pass `global: true` to override system-wide defaults (ownerId=0), which act as the fallback for all users. VECTORIZE requires a premium subscription for non-admins.',
        security: [['Bearer' => []]],
        tags: ['Configuration'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['defaults'],
                properties: [
                    new OA\Property(
                        property: 'defaults',
                        type: 'object',
                        description: 'Map of capability name to model ID',
                        example: ['CHAT' => 53, 'SORT' => 12, 'ANALYZE' => 53]
                    ),
                    new OA\Property(
                        property: 'global',
                        type: 'boolean',
                        description: 'Admin-only: when true, saves as system-wide defaults that apply to all users as fallback',
                        example: false
                    ),
                ]
            )
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Defaults saved successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Default models saved successfully'),
                new OA\Property(
                    property: 'skipped',
                    type: 'object',
                    description: 'Capabilities whose model ID was rejected because the model no longer exists or is inactive',
                    example: ['TEXT2PIC' => 99],
                    nullable: true
                ),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid request body')]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(
        response: 403,
        description: 'Forbidden. Two distinct cases: (1) `global: true` used without ROLE_ADMIN, (2) VECTORIZE change attempted without premium subscription.',
        content: new OA\JsonContent(
            oneOf: [
                new OA\Schema(
                    title: 'AdminRequired',
                    description: 'Returned when `global: true` is passed without ROLE_ADMIN',
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'Admin access required for global defaults'),
                    ]
                ),
                new OA\Schema(
                    title: 'PremiumRequired',
                    description: 'Returned when a non-admin user attempts to change the VECTORIZE model',
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'requires_premium'),
                        new OA\Property(property: 'capability', type: 'string', example: 'VECTORIZE'),
                        new OA\Property(property: 'message', type: 'string', example: 'Switching the embedding model requires a premium subscription'),
                        new OA\Property(property: 'currentLevel', type: 'string', example: 'FREE'),
                    ]
                ),
            ]
        )
    )]
    public function saveDefaultModels(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['defaults']) || !is_array($data['defaults'])) {
            return $this->json(['error' => 'Invalid data'], Response::HTTP_BAD_REQUEST);
        }

        $global = !empty($data['global']);
        if ($global && !$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['error' => 'Admin access required for global defaults'], Response::HTTP_FORBIDDEN);
        }

        $ownerId = $global ? 0 : $user->getId();
        $validCapabilities = ['SORT', 'CHAT', 'MEM', 'VECTORIZE', 'PIC2TEXT', 'TEXT2PIC', 'PIC2PIC', 'TEXT2VID', 'IMG2VID', 'SOUND2TEXT', 'TEXT2SOUND', 'ANALYZE'];

        // Premium gate for VECTORIZE: switching the embedding model is
        // a paid feature even at the per-user scope, because every
        // search the user runs afterwards burns embedding API credit on
        // the new model, AND because we want to keep this consistent
        // with the global path (AdminEmbeddingController::switch).
        // Admins always pass the guard.
        //
        // CRITICAL (#891): only fire the gate when the user is ACTUALLY
        // changing VECTORIZE. The frontend's `saveConfiguration()` echoes
        // EVERY non-null capability on every save — including the
        // unchanged VECTORIZE seeded from `getDefaultModels()` — so a
        // NEW user who only wants to change their CHAT model would
        // otherwise get a 403 here and watch the entire save silently
        // fail (CHAT/TEXT2PIC/etc all blocked as collateral damage).
        // The VECTORIZE read side resolves through ownerId=0 (see
        // `getDefaultModels()` above for the matching rationale), so an
        // unchanged echo is byte-equal to the global row.
        $currentVectorizeId = $this->resolveCurrentVectorizeModelId();
        $vectorizeChanged = isset($data['defaults']['VECTORIZE'])
            && (int) $data['defaults']['VECTORIZE'] !== $currentVectorizeId;

        if ($vectorizeChanged) {
            try {
                $this->embeddingChangeGuard->assertCanChange($user);
            } catch (PremiumRequiredException $e) {
                return $this->json([
                    'error' => 'requires_premium',
                    'capability' => 'VECTORIZE',
                    'message' => $e->getMessage(),
                    'currentLevel' => $e->currentLevel,
                ], Response::HTTP_FORBIDDEN);
            }
        }

        $skipped = [];

        foreach ($data['defaults'] as $capability => $modelId) {
            if (!in_array($capability, $validCapabilities)) {
                continue;
            }

            // Same rationale as the premium gate above: don't write an
            // unchanged VECTORIZE to a per-user row that is never read
            // by anyone (VECTORIZE always resolves through ownerId=0)
            // AND don't invalidate the embedding-metadata cache for a
            // value that didn't actually change. Belt-and-braces with
            // the gate skip so the no-op save stays a true no-op.
            if ('VECTORIZE' === $capability && !$vectorizeChanged) {
                continue;
            }

            $model = $this->modelRepository->find($modelId);
            if (!$model || 1 !== $model->getActive()) {
                $skipped[$capability] = $modelId;
                continue;
            }

            // VECTORIZE controls how the user's OWN files/memories get
            // embedded — explicitly user-scoped. We must NOT silently
            // escalate a user-scoped write into a global config change
            // (raised by Copilot review on PR #853).
            //
            // The only path that may write to ownerId=0 is the `global`
            // flag above, which already requires `ROLE_ADMIN`.
            $targetOwnerId = $ownerId;

            $config = $this->configRepository->findOneBy([
                'ownerId' => $targetOwnerId,
                'group' => 'DEFAULTMODEL',
                'setting' => $capability,
            ]);

            if (!$config) {
                $config = new Config();
                $config->setOwnerId($targetOwnerId);
                $config->setGroup('DEFAULTMODEL');
                $config->setSetting($capability);
            }

            $config->setValue((string) $modelId);
            $this->em->persist($config);
        }

        $this->em->flush();

        // Drop cached active-model snapshot so the very next read
        // (RAG search, /admin/embedding/status) sees the new VECTORIZE
        // model immediately. Skip the invalidation
        // when VECTORIZE didn't actually change — the cache already
        // holds the correct value and there's no point thrashing it
        // on every CHAT-only save (#891).
        if ($vectorizeChanged) {
            $this->embeddingMetadata->invalidate();
        }

        $response = [
            'success' => true,
            'message' => $global ? 'Global default models saved successfully' : 'Default models saved successfully',
        ];

        if (!empty($skipped)) {
            $response['skipped'] = $skipped;
            $response['message'] .= ' (some models were skipped because they are no longer available)';
        }

        return $this->json($response);
    }

    /**
     * Replace the calling user's model configuration with the
     * code-recommended defaults from DefaultModelConfigSeeder.
     *
     * Removes stale per-user overrides and writes fresh ones that
     * match the catalog-recommended models. Other users and the
     * global (ownerId=0) row are unaffected.
     */
    #[Route('/models/defaults/reset', name: 'models_defaults_reset', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/config/models/defaults/reset',
        summary: 'Apply recommended model defaults to own configuration',
        description: 'Replaces all per-user DEFAULTMODEL overrides with the code-recommended defaults (from DefaultModelConfigSeeder). Does NOT modify global defaults — other users are unaffected. Returns the newly written defaults.',
        security: [['Bearer' => []]],
        tags: ['Configuration']
    )]
    #[OA\Response(
        response: 200,
        description: 'Defaults applied successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'message', type: 'string', example: 'Applied 11 recommended defaults (removed 3 previous overrides)'),
                new OA\Property(
                    property: 'defaults',
                    type: 'object',
                    description: 'New default model IDs per capability',
                    example: ['CHAT' => 161, 'SORT' => 76]
                ),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function resetDefaultModels(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $result = $this->modelConfigService->resetUserDefaults($user->getId());

        return $this->json([
            'success' => true,
            'message' => sprintf(
                'Applied %d recommended default(s) (removed %d previous override(s))',
                $result['written'],
                $result['removed'],
            ),
            'defaults' => $result['defaults'],
        ]);
    }

    /**
     * Check if a model is available/ready to use.
     *
     * @param int $modelId Model ID to check
     *
     * @return JsonResponse {available: bool, provider_type: string, message?: string, install_command?: string}
     */
    #[Route('/models/{modelId}/check', name: 'models_check', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/config/models/{modelId}/check',
        summary: 'Check model availability',
        description: 'Checks whether a specific model is ready to use. For local Ollama models it verifies the Ollama server is running. For external providers it validates that the required API keys or environment variables are configured.',
        security: [['Bearer' => []]],
        tags: ['Configuration']
    )]
    #[OA\Parameter(
        name: 'modelId',
        in: 'path',
        required: true,
        description: 'Model database ID',
        schema: new OA\Schema(type: 'integer', example: 53)
    )]
    #[OA\Response(
        response: 200,
        description: 'Model availability status',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'available', type: 'boolean', example: true),
                new OA\Property(
                    property: 'provider_type',
                    type: 'string',
                    enum: ['local', 'external', 'unknown'],
                    description: '`local` for Ollama, `external` for cloud API providers',
                    example: 'external'
                ),
                new OA\Property(property: 'model_name', type: 'string', example: 'llama3.2:latest'),
                new OA\Property(property: 'service', type: 'string', example: 'ollama'),
                new OA\Property(
                    property: 'message',
                    type: 'string',
                    nullable: true,
                    description: 'Human-readable reason when `available` is false',
                    example: 'Ollama server is not running'
                ),
                new OA\Property(
                    property: 'install_command',
                    type: 'string',
                    nullable: true,
                    description: 'Command to pull/install the model (Ollama only)',
                    example: 'docker compose exec ollama ollama pull llama3.2:latest'
                ),
                new OA\Property(
                    property: 'env_var',
                    type: 'string',
                    nullable: true,
                    description: 'Environment variable that must be set for external providers',
                    example: 'OPENAI_API_KEY'
                ),
                new OA\Property(
                    property: 'setup_instructions',
                    type: 'string',
                    nullable: true,
                    description: 'Short setup hint when `env_var` is present',
                    example: 'Set OPENAI_API_KEY in your environment (e.g. .env.local)'
                ),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(
        response: 404,
        description: 'Model not found',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'available', type: 'boolean', example: false),
                new OA\Property(property: 'error', type: 'string', example: 'Model not found'),
            ]
        )
    )]
    public function checkModelAvailability(
        int $modelId,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $model = $this->modelRepository->find($modelId);
        if (!$model) {
            return $this->json([
                'available' => false,
                'error' => 'Model not found',
            ], Response::HTTP_NOT_FOUND);
        }

        $service = strtolower($model->getService());
        $providerType = 'unknown';
        $available = false;
        $message = null;
        $installCommand = null;
        $envVar = null;

        // Determine provider type and check availability
        if ('ollama' === $service) {
            $providerType = 'local';

            // Check if Ollama provider is available
            try {
                $provider = $this->providerRegistry->getChatProvider('ollama');

                // Check if the specific model exists
                $modelName = $model->getProviderId() ?: $model->getName();

                // Try to list available models
                $status = $provider->getStatus();
                if (!empty($status['healthy'])) {
                    // Model is available if Ollama is running
                    // We assume it's available; the user will get a proper error if not
                    $available = true;
                } else {
                    $message = 'Ollama server is not running';
                }

                // Always provide install command for Ollama models
                $installCommand = "docker compose exec ollama ollama pull {$modelName}";
            } catch (\Exception $e) {
                $message = 'Ollama not available: '.$e->getMessage();
            }
        } elseif (null !== ($registeredProvider = $this->findProviderForModelService($service))) {
            // Same rules for every registered provider: use getRequiredEnvVars() (API keys, URLs, etc.)
            $providerType = 'external';
            $secretCheck = $this->evaluateProviderRequiredConfiguration($registeredProvider, $service);
            $available = $secretCheck['available'];
            $message = $secretCheck['message'];
            $envVar = $secretCheck['env_var'];
        } else {
            // Unknown provider (e.g., test, custom)
            $available = true; // Assume available
        }

        $response = [
            'available' => $available,
            'provider_type' => $providerType,
            'model_name' => $model->getProviderId() ?: $model->getName(),
            'service' => $service,
        ];

        if ($message) {
            $response['message'] = $message;
        }

        if ($installCommand) {
            $response['install_command'] = $installCommand;
        }

        if ($envVar) {
            $response['env_var'] = $envVar;
            $response['setup_instructions'] = "Set {$envVar} in your environment (e.g. .env.local)";
        }

        return $this->json($response);
    }

    private function findProviderForModelService(string $serviceLower): ?ProviderMetadataInterface
    {
        foreach ($this->providerRegistry->getUniqueProviders() as $provider) {
            if (strtolower($provider->getName()) === $serviceLower) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Resolve the currently-bound VECTORIZE model id from the global
     * config row (`ownerId=0`), which is what `getDefaultModels()` exposes
     * to the frontend dropdown and what every embedding read site uses
     * as the canonical answer.
     *
     * Returns 0 when no row is configured yet (treat as "no current
     * binding"), so any incoming non-zero VECTORIZE id is correctly
     * classified as a change.
     */
    private function resolveCurrentVectorizeModelId(): int
    {
        $config = $this->configRepository->findOneBy([
            'ownerId' => 0,
            'group' => 'DEFAULTMODEL',
            'setting' => 'VECTORIZE',
        ]);

        return $config ? (int) $config->getValue() : 0;
    }

    /**
     * True if the env var is set to a non-placeholder non-empty string.
     */
    private function isMeaningfulEnvValueSet(string $envName): bool
    {
        $value = $_ENV[$envName] ?? getenv($envName);
        if (!\is_string($value) || '' === $value) {
            return false;
        }

        return 'your-api-key-here' !== $value;
    }

    /**
     * @return array{available: bool, message: ?string, env_var: ?string}
     */
    private function evaluateProviderRequiredConfiguration(ProviderMetadataInterface $provider, string $serviceLabel): array
    {
        $requiredVars = $provider->getRequiredEnvVars();

        if ([] === $requiredVars) {
            return ['available' => true, 'message' => null, 'env_var' => null];
        }

        foreach ($requiredVars as $envName => $meta) {
            if (false === ($meta['required'] ?? true)) {
                continue;
            }

            $candidates = (isset($meta['any_of']) && \is_array($meta['any_of']))
                ? array_values(array_filter($meta['any_of'], 'is_string'))
                : [$envName];

            if ([] === $candidates) {
                $candidates = [$envName];
            }

            $satisfied = false;
            foreach ($candidates as $candidate) {
                if ('' !== $candidate && $this->isMeaningfulEnvValueSet($candidate)) {
                    $satisfied = true;
                    break;
                }
            }

            if (!$satisfied) {
                $first = $candidates[0];

                return [
                    'available' => false,
                    'message' => "Configuration not complete for {$serviceLabel}",
                    'env_var' => $first,
                ];
            }
        }

        return ['available' => true, 'message' => null, 'env_var' => null];
    }

    /**
     * Get status of all features and services (Web Search, AI Providers, Processing Services, etc.)
     * Only available in development mode (APP_ENV=dev). Returns 403 Forbidden in production.
     */
    #[Route('/features', name: 'features_status', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/config/features',
        summary: 'Get feature and service status (dev only)',
        description: 'Returns the live status of all configured features, AI providers, and infrastructure services. **Only available in `APP_ENV=dev`** – returns 403 Forbidden in production. Useful during local development to verify that all required services are reachable and correctly configured.',
        security: [['Bearer' => []]],
        tags: ['Configuration']
    )]
    #[OA\Response(
        response: 200,
        description: 'Feature status map with summary',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'features',
                    type: 'object',
                    description: 'Map of feature ID to status object',
                    additionalProperties: new OA\AdditionalProperties(
                        properties: [
                            new OA\Property(property: 'id', type: 'string', example: 'web-search'),
                            new OA\Property(property: 'category', type: 'string', example: 'AI Features'),
                            new OA\Property(property: 'name', type: 'string', example: 'Web Search'),
                            new OA\Property(property: 'enabled', type: 'boolean', example: true),
                            new OA\Property(property: 'status', type: 'string', enum: ['active', 'healthy', 'unhealthy', 'disabled'], example: 'active'),
                            new OA\Property(property: 'message', type: 'string', example: 'Web search is active and ready to use'),
                            new OA\Property(property: 'setup_required', type: 'boolean', example: false),
                        ]
                    )
                ),
                new OA\Property(
                    property: 'summary',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'total', type: 'integer', example: 10),
                        new OA\Property(property: 'healthy', type: 'integer', example: 8),
                        new OA\Property(property: 'unhealthy', type: 'integer', example: 2),
                        new OA\Property(property: 'all_ready', type: 'boolean', example: false),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 403, description: 'Only available in development mode (APP_ENV=dev)')]
    public function getFeaturesStatus(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Only allow in development mode
        $env = $_ENV['APP_ENV'] ?? 'prod';
        if ('dev' !== $env) {
            return $this->json(['error' => 'Feature only available in development mode'], Response::HTTP_FORBIDDEN);
        }

        $features = [];

        // ========== AI Features ==========

        // Web Search (Brave API)
        $braveEnabled = $this->braveSearchService->isEnabled();
        $features['web-search'] = [
            'id' => 'web-search',
            'category' => 'AI Features',
            'name' => 'Web Search',
            'enabled' => $braveEnabled,
            'status' => $braveEnabled ? 'active' : 'disabled',
            'message' => $braveEnabled
                ? 'Web search is active and ready to use'
                : 'Web search requires Brave Search API configuration',
            'setup_required' => !$braveEnabled,
            'env_vars' => [
                'BRAVE_SEARCH_API_KEY' => [
                    'required' => true,
                    'set' => !empty($_ENV['BRAVE_SEARCH_API_KEY'] ?? ''),
                    'hint' => 'Get your API key from https://api.search.brave.com/',
                ],
                'BRAVE_SEARCH_ENABLED' => [
                    'required' => true,
                    'set' => ($_ENV['BRAVE_SEARCH_ENABLED'] ?? 'false') === 'true',
                    'hint' => 'Set to "true" to enable web search',
                ],
            ],
        ];

        // Image Generation
        $imageModels = $this->modelRepository->findBy(['active' => 1, 'tag' => 'TEXT2PIC']);
        $hasImageModels = count($imageModels) > 0;
        $features['image-gen'] = [
            'id' => 'image-gen',
            'category' => 'AI Features',
            'name' => 'Image Generation',
            'enabled' => $hasImageModels,
            'status' => $hasImageModels ? 'active' : 'disabled',
            'message' => $hasImageModels
                ? count($imageModels).' image generation model(s) available'
                : 'No image generation models configured',
            'setup_required' => !$hasImageModels,
            'models_available' => count($imageModels),
        ];

        // ========== AI Providers (Dynamic from ProviderRegistry) ==========

        $providersMetadata = $this->providerRegistry->getProvidersMetadata();

        foreach ($providersMetadata as $providerName => $providerData) {
            // Skip test provider in production (only show in dev mode)
            if ('test' === $providerName && 'dev' !== $env) {
                continue; // @phpstan-ignore-line (env is dynamic at runtime)
            }

            // Get model count from database for this provider
            $modelsCount = 0;
            try {
                $models = $this->modelRepository->findBy([
                    'provider' => $providerName,
                    'active' => true,
                ]);
                $modelsCount = count($models);
            } catch (\Exception $e) {
                // Ignore
            }

            // Get URL for services that have one
            $url = null;
            if ('ollama' === $providerName) {
                $url = $_ENV['OLLAMA_BASE_URL'] ?? null;
            }

            // Convert env_vars format (check if actually set in environment)
            $envVars = [];
            foreach ($providerData['env_vars'] ?? [] as $varName => $varConfig) {
                $envVars[$varName] = [
                    'required' => $varConfig['required'],
                    'set' => !empty($_ENV[$varName] ?? ''),
                    'hint' => $varConfig['hint'],
                ];
            }

            // Determine status: active if enabled and healthy, unhealthy if enabled but not healthy, disabled otherwise
            $status = 'disabled';
            if ($providerData['enabled']) {
                $status = ('healthy' === $providerData['status']) ? 'active' : 'unhealthy';
            }

            $features[$providerName] = [
                'id' => $providerName,
                'category' => 'AI Providers',
                'name' => $providerData['name'],
                'enabled' => $providerData['enabled'],
                'status' => $status,
                'message' => $providerData['enabled']
                    ? $providerData['description']
                    : ($providerData['status_message'] ?? 'API key not configured'),
                'setup_required' => $providerData['setup_required'],
                'env_vars' => $envVars,
                'models_available' => $modelsCount,
                'url' => $url,
            ];
        }

        // ========== Processing Services ==========

        // Whisper.cpp (Speech-to-Text) - runs in backend container
        $whisperHealthy = $this->whisperService->isAvailable();
        $availableModels = $whisperHealthy ? $this->whisperService->getAvailableModels() : [];
        $features['whisper'] = [
            'id' => 'whisper',
            'category' => 'Processing Services',
            'name' => 'Whisper.cpp',
            'enabled' => $whisperHealthy,
            'status' => $whisperHealthy ? 'healthy' : 'unhealthy',
            'message' => $whisperHealthy
                ? 'Speech-to-text transcription is ready'
                : 'Whisper.cpp binary or models not found',
            'setup_required' => !$whisperHealthy,
            'models_available' => count($availableModels),
        ];

        // Apache Tika (Document Processing)
        $tikaUrl = $_ENV['TIKA_BASE_URL'] ?? 'http://tika:9998';
        $tikaHttpUser = $_ENV['TIKA_HTTP_USER'] ?? null;
        $tikaHttpPass = $_ENV['TIKA_HTTP_PASS'] ?? null;
        $tikaHealthy = $this->checkServiceHealth($tikaUrl.'/tika', $tikaHttpUser, $tikaHttpPass);

        // Try to get Tika version
        $tikaVersion = '';
        if ($tikaHealthy) {
            try {
                $versionHttpOptions = ['timeout' => 2];
                if (!empty($tikaHttpUser)) {
                    $versionHttpOptions['header'] = 'Authorization: Basic '.base64_encode($tikaHttpUser.':'.($tikaHttpPass ?? ''));
                }
                $versionResponse = @file_get_contents($tikaUrl.'/version', false, stream_context_create([
                    'http' => $versionHttpOptions,
                ]));
                if ($versionResponse) {
                    $tikaVersion = trim($versionResponse);
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        $features['tika'] = [
            'id' => 'tika',
            'category' => 'Processing Services',
            'name' => 'Apache Tika',
            'enabled' => true,
            'status' => $tikaHealthy ? 'healthy' : 'unhealthy',
            'message' => $tikaHealthy
                ? 'Document processing service is running'
                : 'Tika service is not responding',
            'setup_required' => false,
            'url' => $tikaUrl,
            'version' => $tikaVersion,
        ];

        // Qdrant - User memories with vector search
        $qdrantUrl = $_ENV['QDRANT_URL'] ?? '';
        $memoryServiceAvailable = $this->memoryService->isAvailable();

        // Build status message and get service info
        $memoryMessage = '';
        $memoryWarnings = [];
        $memoryVersion = 'unknown';
        $memoryStats = [];

        if ($memoryServiceAvailable) {
            try {
                $healthDetails = $this->memoryService->getQdrantClient()->getHealthDetails();
                $memoryVersion = $healthDetails['version'] ?? 'unknown';
                $memoryStats = $healthDetails['qdrant'] ?? [];

                $memoryMessage = 'Qdrant is connected and ready';
            } catch (\Throwable $e) {
                $memoryMessage = 'Qdrant available but health check failed';
                $memoryWarnings[] = $e->getMessage();
            }
        } else {
            if (empty($qdrantUrl) || 'http://' === $qdrantUrl || 'https://' === $qdrantUrl) {
                $memoryMessage = 'Qdrant URL not configured';
            } else {
                $memoryMessage = 'Qdrant not reachable at configured URL';
            }
        }

        $features['memory-service'] = [
            'id' => 'memory-service',
            'category' => 'Processing Services',
            'name' => 'Qdrant Vector Database',
            'enabled' => $memoryServiceAvailable,
            'status' => $memoryServiceAvailable ? 'healthy' : 'unhealthy',
            'message' => $memoryMessage,
            'warnings' => $memoryWarnings,
            'setup_required' => !$memoryServiceAvailable,
            'url' => $qdrantUrl ?: 'not configured',
            'version' => $memoryVersion,
            'stats' => $memoryStats,
            'env_vars' => [
                'QDRANT_URL' => [
                    'required' => true,
                    'set' => !empty($qdrantUrl) && 'http://' !== $qdrantUrl && 'https://' !== $qdrantUrl,
                    'hint' => 'Internal Docker service URL',
                    'example' => 'http://qdrant:6333',
                ],
            ],
        ];

        // ========== Infrastructure Services ==========

        // Database (MariaDB)
        $dbHealthy = false;
        $dbVersion = '';
        try {
            $this->em->getConnection()->executeQuery('SELECT 1');
            $dbHealthy = true;

            // Get DB version
            $versionResult = $this->em->getConnection()->executeQuery('SELECT VERSION()')->fetchOne();
            if ($versionResult) {
                $dbVersion = explode('-', $versionResult)[0];
            }
        } catch (\Exception $e) {
            $dbHealthy = false;
        }

        $features['database'] = [
            'id' => 'database',
            'category' => 'Infrastructure',
            'name' => 'MariaDB',
            'enabled' => true,
            'status' => $dbHealthy ? 'healthy' : 'unhealthy',
            'message' => $dbHealthy
                ? 'Database connection is active and responding'
                : 'Database connection failed',
            'setup_required' => false,
            'version' => $dbVersion,
        ];

        // Redis (cache, locks, rate-limiter, sessions, realtime fan-out)
        $redisHealthy = $this->redisService->ping();
        $redisError = $this->redisService->getLastConnectionError();
        $redisDsn = (string) ($_ENV['REDIS_DSN'] ?? '');

        $features['redis'] = [
            'id' => 'redis',
            'category' => 'Infrastructure',
            'name' => 'Redis',
            'enabled' => true,
            'status' => $redisHealthy ? 'healthy' : 'unhealthy',
            'message' => $redisHealthy
                ? 'Cache, locks, rate-limiter, sessions and realtime fan-out are operational'
                // Dev-only endpoint (403 in prod), so the raw connection
                // error is safe and far more useful than a generic message.
                : 'Redis unreachable'.(null !== $redisError ? ': '.$redisError->getMessage() : ''),
            'setup_required' => !$redisHealthy,
            'url' => '' !== $redisDsn ? $this->redactDsn($redisDsn) : 'not configured',
            'version' => $redisHealthy ? ($this->redisService->serverVersion() ?? '') : '',
            'env_vars' => [
                'REDIS_DSN' => [
                    'required' => true,
                    'set' => '' !== $redisDsn,
                    'hint' => 'Redis connection DSN shared by cache, locks, rate-limiter and Messenger (e.g. redis://redis:6379)',
                ],
            ],
        ];

        // Centrifugo (realtime WebSocket gateway)
        $realtimeEnabled = 'true' === ($_ENV['REALTIME_ENABLED'] ?? 'false');
        $realtimeApiUrl = (string) ($_ENV['REALTIME_API_URL'] ?? '');
        // REALTIME_API_URL points at the server API (…/api); the health
        // endpoint lives at the server root (health.enabled in config.json).
        $centrifugoBaseUrl = '' !== $realtimeApiUrl
            ? (string) preg_replace('#/api/?$#', '', $realtimeApiUrl)
            : '';
        $centrifugoHealthy = $realtimeEnabled
            && '' !== $centrifugoBaseUrl
            && $this->checkServiceHealth($centrifugoBaseUrl.'/health');

        if (!$realtimeEnabled) {
            $centrifugoStatus = 'disabled';
            $centrifugoMessage = 'Realtime is disabled (REALTIME_ENABLED=false) — clients see fresh data via REST only, without push updates';
        } elseif ($centrifugoHealthy) {
            $centrifugoStatus = 'healthy';
            $centrifugoMessage = 'Realtime WebSocket gateway is running (chat streaming, widget events, presence)';
        } else {
            $centrifugoStatus = 'unhealthy';
            $centrifugoMessage = '' === $centrifugoBaseUrl
                ? 'REALTIME_API_URL not configured'
                : 'Centrifugo is not responding';
        }

        $features['centrifugo'] = [
            'id' => 'centrifugo',
            'category' => 'Infrastructure',
            'name' => 'Centrifugo',
            'enabled' => $realtimeEnabled,
            'status' => $centrifugoStatus,
            'message' => $centrifugoMessage,
            'setup_required' => !$centrifugoHealthy,
            'url' => '' !== $centrifugoBaseUrl ? $centrifugoBaseUrl : 'not configured',
            'env_vars' => [
                'REALTIME_ENABLED' => [
                    'required' => true,
                    'set' => $realtimeEnabled,
                    'hint' => 'Master switch for WebSocket publishing (no SSE fallback)',
                ],
                'REALTIME_API_URL' => [
                    'required' => true,
                    'set' => '' !== $realtimeApiUrl,
                    'hint' => 'Centrifugo server API endpoint, e.g. http://centrifugo:8000/api',
                ],
            ],
        ];

        // Count ready services
        $totalServices = count($features);
        $healthyServices = count(array_filter($features, fn ($f) => in_array($f['status'], ['active', 'healthy'])
        ));

        return $this->json([
            'features' => $features,
            'summary' => [
                'total' => $totalServices,
                'healthy' => $healthyServices,
                'unhealthy' => $totalServices - $healthyServices,
                'all_ready' => $healthyServices === $totalServices,
            ],
        ]);
    }

    /**
     * Strip credentials from a DSN before exposing it (`redis://user:pass@host` → `redis://***@host`).
     */
    private function redactDsn(string $dsn): string
    {
        return (string) preg_replace('#://[^@/]*@#', '://***@', $dsn);
    }

    /**
     * Check if a service is healthy by making a simple HTTP request.
     */
    private function checkServiceHealth(string $url, ?string $httpUser = null, ?string $httpPass = null): bool
    {
        try {
            $httpOptions = [
                'timeout' => 2,
                'ignore_errors' => true,
            ];

            // Send HTTP Basic Auth when the service is protected (e.g. Tika)
            if (!empty($httpUser)) {
                $credentials = base64_encode($httpUser.':'.($httpPass ?? ''));
                $httpOptions['header'] = 'Authorization: Basic '.$credentials;
            }

            $context = stream_context_create(['http' => $httpOptions]);

            $response = @file_get_contents($url, false, $context);

            if (false === $response) {
                return false;
            }

            // Check HTTP response code
            if (isset($http_response_header[0])) {
                preg_match('/\d{3}/', $http_response_header[0], $matches);
                $statusCode = isset($matches[0]) ? (int) $matches[0] : 0;

                // Auth failures mean the service is misconfigured/unreachable for us
                if (401 === $statusCode || 403 === $statusCode) {
                    return false;
                }

                return $statusCode >= 200 && $statusCode < 500; // Accept 2xx, 3xx, other 4xx (not 5xx)
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
