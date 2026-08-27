<?php

namespace App\Controller;

use App\AI\Credential\ChatReadinessService;
use App\AI\Credential\ProviderKeyStore;
use App\AI\Credential\SecretValueGuard;
use App\AI\Interface\ProviderMetadataInterface;
use App\AI\Service\AiProviderDisclosure;
use App\AI\Service\ProviderRegistry;
use App\Entity\Config;
use App\Entity\User;
use App\Model\ModelCatalog;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Service\Auth\DemoLoginHint;
use App\Service\BillingService;
use App\Service\Branding\BrandingService;
use App\Service\Capability\CapabilityService;
use App\Service\Client\ClientContextResolver;
use App\Service\Client\MobileVersionService;
use App\Service\Embedding\EmbeddingMetadataService;
use App\Service\Embedding\EmbeddingModelChangeGuard;
use App\Service\Embedding\Exception\PremiumRequiredException;
use App\Service\GuestChatConfig;
use App\Service\Infrastructure\RedisService;
use App\Service\LocalAi\LocalAiDownloadStatusService;
use App\Service\MailerConfig;
use App\Service\MarketingNews\MarketingNewsConfig;
use App\Service\ModelConfigService;
use App\Service\Plugin\PluginManager;
use App\Service\RegistrationConfig;
use App\Service\SavedTask\SavedTaskConfig;
use App\Service\Search\BraveSearchService;
use App\Service\Setup\SetupStateService;
use App\Service\UsageTaximeterConfig;
use App\Service\UserMemoryService;
use App\Service\WebSpeechConfig;
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
        private UsageTaximeterConfig $usageTaximeterConfig,
        private RegistrationConfig $registrationConfig,
        private GuestChatConfig $guestChatConfig,
        private WebSpeechConfig $webSpeechConfig,
        private SavedTaskConfig $savedTaskConfig,
        private ChatReadinessService $chatReadiness,
        private DemoLoginHint $demoLoginHint,
        private SetupStateService $setupState,
        private AiProviderDisclosure $aiProviderDisclosure,
        private LocalAiDownloadStatusService $localAiDownloadStatus,
        private MailerConfig $mailerConfig,
        private CapabilityService $capabilityService,
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
                    property: 'auth',
                    type: 'object',
                    description: 'Authentication surface flags. Lets the frontend hide sign-up affordances when the operator runs an SSO-/OIDC-only instance.',
                    properties: [
                        new OA\Property(property: 'registrationEnabled', type: 'boolean', example: true, description: 'When false, local email/password self-registration is disabled (set REGISTRATION_ENABLED=false, e.g. for OIDC-only deployments). The /register endpoint is also refused server-side.'),
                        new OA\Property(property: 'guestChatEnabled', type: 'boolean', example: true, description: 'When false, the anonymous guest trial chat is disabled (set GUEST_CHAT_ENABLED=false, e.g. for OIDC-only deployments): the frontend sends unauthenticated visitors to /login and every /api/v1/guest endpoint is refused server-side.'),
                        new OA\Property(property: 'mailerConfigured', type: 'boolean', example: true, description: 'False when MAILER_DSN is unset or the null transport. The forgot-password page then shows the CLI reset instead of pretending an email will arrive.'),
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
                        new OA\Property(property: 'savedTasks', type: 'boolean', example: false, description: 'When true, AI Instructions shows Saved Task chrome. Widget chat never runs Saved Tasks.'),
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
                        new OA\Property(property: 'primaryColorDark', type: 'string', example: '', description: 'Dark-mode primary color; empty string auto-derives a dark tint from primaryColor'),
                        new OA\Property(property: 'secondaryColorDark', type: 'string', example: '', description: 'Dark-mode secondary color; empty string reuses secondaryColor'),
                        new OA\Property(property: 'accentColorDark', type: 'string', example: '', description: 'Dark-mode accent color; empty string reuses accentColor'),
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
                            property: 'webSpeechEnabled',
                            type: 'boolean',
                            example: true,
                            description: 'When false, the frontend never uses the browser\'s cloud-backed Web Speech API for speech-to-text (set WEB_SPEECH_ENABLED=false on air-gapped instances) and records for the server-side transcription path instead, or hides the microphone when speechToTextAvailable is false too.'
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
                            new OA\Property(
                                property: 'chatCommands',
                                type: 'array',
                                description: 'Slash-commands this plugin registers in the chat composer',
                                items: new OA\Items(
                                    properties: [
                                        new OA\Property(property: 'command', type: 'string', example: 'fastbill'),
                                        new OA\Property(property: 'endpoint', type: 'string', example: '/chat'),
                                        new OA\Property(property: 'description', type: 'string', example: 'Talk to your FastBill account'),
                                    ],
                                    type: 'object'
                                )
                            ),
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
                            property: 'updateEnforceAfter',
                            type: 'string',
                            example: '2026-07-17T12:00:00Z',
                            description: 'ISO-8601 grace-period deadline. Before this timestamp the update is not blocking; empty means immediate enforcement.'
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
                    property: 'usageTaximeter',
                    type: 'object',
                    description: 'In-chat usage-display master switch (admin-controlled, on by default). When false, the frontend renders no consumption bar/ring and no per-message token-cost badge, and performs no usage-summary fetch. Does not affect the Statistics page.',
                    properties: [
                        new OA\Property(
                            property: 'enabled',
                            type: 'boolean',
                            example: true,
                            description: 'Whether the in-chat usage display is enabled platform-wide (default true).'
                        ),
                    ]
                ),
                new OA\Property(
                    property: 'aiProviders',
                    type: 'array',
                    description: 'Display names of the AI providers a user\'s input can reach on this instance, for the disclosure App Store Review Guideline 5.1.2(i) requires. Empty when none are configured.',
                    items: new OA\Items(type: 'string', example: 'Anthropic')
                ),
                new OA\Property(
                    property: 'unavailableProviders',
                    type: 'array',
                    description: 'AI providers that are disabled due to missing API keys (only for authenticated users)',
                    items: new OA\Items(type: 'string', example: 'Anthropic'),
                    nullable: true
                ),
                new OA\Property(
                    property: 'setup',
                    type: 'object',
                    description: 'First-run setup status. wizardRequired, wizardEnabled and demoLoginHint are public so the SPA can route a virgin install into the setup wizard; chatReady is only set for authenticated users.',
                    nullable: true,
                    properties: [
                        new OA\Property(
                            property: 'wizardRequired',
                            type: 'boolean',
                            example: false,
                            description: 'True only on a virgin installation that still needs its first administrator: no BCONFIG SETUP.COMPLETED flag, not a single BUSER row, and SETUP_WIZARD_ENABLED not disabled. The SPA then redirects every route to the setup wizard, and the rest of the API answers 503 SETUP_REQUIRED. False on every existing installation.'
                        ),
                        new OA\Property(
                            property: 'wizardEnabled',
                            type: 'boolean',
                            example: true,
                            description: 'False only when the operator set SETUP_WIZARD_ENABLED=false. The wizard then never applies on this installation, no matter how empty it is — the intended setup for SSO/OIDC deployments where the administrator arrives through IdP roles and no local account is ever created. The SPA uses this to stop re-checking the setup state at all. True by default.'
                        ),
                        new OA\Property(
                            property: 'chatReady',
                            type: 'boolean',
                            example: true,
                            description: 'True when a real AI provider (cloud key or a pulled local Ollama model) can serve the requesting user\'s effective default chat model. The built-in TestProvider demo responder does not count. Omitted for anonymous clients.'
                        ),
                        new OA\Property(
                            property: 'demoLoginHint',
                            type: 'boolean',
                            example: false,
                            description: 'True only on a fresh dev/test install whose seeded admin@synaplan.com password is still the fixture default. The login page may then show that account. Always false in production.'
                        ),
                    ]
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
            'savedTasks' => $this->savedTaskConfig->isEnabled($user?->getId()),
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
            'webSpeechEnabled' => $this->webSpeechConfig->isEnabled(),
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
                    'chatCommands' => $plugin->chatCommands,
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
        $setup = [
            // Public on purpose: this is the ONLY signal the SPA has to route a
            // virgin install into the wizard, and it is the one route the setup
            // lockdown lets through. It leaks nothing — on every installation
            // that has ever had a user it is simply false.
            'wizardRequired' => $this->setupState->isSetupRequired(),
            // Distinguishes "already set up" from "the operator switched the
            // wizard off". Both leave wizardRequired false, but only the second
            // one is permanent, which is what lets an SSO/OIDC deployment stop
            // asking about the setup state altogether.
            'wizardEnabled' => $this->setupState->isWizardEnabled(),
            'demoLoginHint' => $this->demoLoginHint->isVisible(),
        ];
        if ($user) {
            // READ ONLY. Repairing a broken default is an explicit action
            // (`app:provider:auto-default` at container start, or an admin
            // saving a key) — never a side effect of this GET.
            $unavailableProviders = $this->chatReadiness->unavailableProviderNames();

            // First-run signal: can a REAL AI provider answer chat for THIS
            // user? The built-in TestProvider does not count — it is canned
            // demo text. The frontend replaces chat with a setup tombstone
            // (admins go to /admin/setup; others to the public docs) while
            // this is false. Evaluated per user so a working per-user model
            // override is honoured, exactly like the chat pipeline resolves it.
            $setup['chatReady'] = $this->chatReadiness->isChatReady(userId: $user->getId());
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
            'updateEnforceAfter' => $this->mobileVersionService->getUpdateEnforceAfter(),
            'iosAppUrl' => $storeUrls['ios'],
            'androidAppUrl' => $storeUrls['android'],
        ];

        $response = [
            'billing' => [
                'enabled' => $this->billingService->isEnabled(),
            ],
            'auth' => [
                // Default ON; operators set REGISTRATION_ENABLED=false for
                // SSO-/OIDC-only instances so no local sign-up is offered.
                'registrationEnabled' => $this->registrationConfig->isEnabled(),
                // Default ON; operators set GUEST_CHAT_ENABLED=false so
                // unauthenticated visitors are sent to /login instead of the
                // anonymous guest trial (issue #1517).
                'guestChatEnabled' => $this->guestChatConfig->isEnabled(),
                'mailerConfigured' => $this->mailerConfig->isConfigured(),
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
            // MOBILE-APP SEAM (App Review 5.1.2(i)): the app's first-run consent
            // screen has to name the AI providers, and it runs before sign-in —
            // so the list ships with the public config. Empty on an instance
            // with no usable chat provider; the client then falls back to
            // wording without names.
            'aiProviders' => $this->aiProviderDisclosure->chatProviderNames(),
            'marketingNews' => [
                'enabled' => $this->marketingNewsConfig->isEnabled(),
            ],
            'usageTaximeter' => [
                'enabled' => $this->usageTaximeterConfig->isEnabled(),
            ],
        ];

        if ($user && !empty($unavailableProviders)) {
            $response['unavailableProviders'] = $unavailableProviders;
        }
        $response['setup'] = $setup;

        return $this->json($response);
    }

    /**
     * Expose supported file formats, languages and summary options so consumer
     * integrations can discover capabilities dynamically instead of hardcoding
     * lists that drift when Synaplan adds support for new formats/languages.
     *
     * Public (no auth): the descriptor is static, non-sensitive capability
     * metadata sourced from the same constants the runtime enforces (#676).
     */
    #[Route('/capabilities', name: 'capabilities', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/config/capabilities',
        summary: 'Get supported file formats, languages and summary options',
        description: 'Returns the file formats accepted for upload (grouped by category), the languages available for '
            .'translation/summary output, the summary types/lengths/focus areas, and the maximum upload size. Consumer '
            .'integrations (e.g. synaplan-nextcloud, synaplan-opencloud) should read this instead of hardcoding lists. '
            .'No authentication required.',
        tags: ['Configuration']
    )]
    #[OA\Response(
        response: 200,
        description: 'Capability descriptor',
        content: new OA\JsonContent(
            required: ['file_formats', 'languages', 'summary', 'max_file_size_bytes'],
            properties: [
                new OA\Property(
                    property: 'file_formats',
                    type: 'object',
                    description: 'Allowed upload extensions (lowercase, no leading dot) grouped by category. '
                        .'A category is omitted when it has no allowed extensions, and an "other" bucket holds any '
                        .'allowed extension without a dedicated category, so clients should tolerate extra keys.',
                    properties: [
                        new OA\Property(property: 'text', type: 'array', items: new OA\Items(type: 'string', example: 'txt')),
                        new OA\Property(property: 'documents', type: 'array', items: new OA\Items(type: 'string', example: 'pdf')),
                        new OA\Property(property: 'spreadsheets', type: 'array', items: new OA\Items(type: 'string', example: 'xlsx')),
                        new OA\Property(property: 'presentations', type: 'array', items: new OA\Items(type: 'string', example: 'pptx')),
                        new OA\Property(property: 'images', type: 'array', items: new OA\Items(type: 'string', example: 'png')),
                        new OA\Property(property: 'audio', type: 'array', items: new OA\Items(type: 'string', example: 'mp3')),
                        new OA\Property(property: 'video', type: 'array', items: new OA\Items(type: 'string', example: 'mp4')),
                        new OA\Property(property: 'calendar', type: 'array', items: new OA\Items(type: 'string', example: 'ics')),
                    ]
                ),
                new OA\Property(
                    property: 'languages',
                    type: 'array',
                    description: 'Language codes available for translation and summary output.',
                    items: new OA\Items(type: 'string', example: 'en')
                ),
                new OA\Property(
                    property: 'summary',
                    type: 'object',
                    required: ['types', 'lengths', 'focus_areas'],
                    properties: [
                        new OA\Property(
                            property: 'types',
                            type: 'array',
                            items: new OA\Items(type: 'string', example: 'abstractive')
                        ),
                        new OA\Property(
                            property: 'lengths',
                            type: 'array',
                            items: new OA\Items(type: 'string', example: 'medium')
                        ),
                        new OA\Property(
                            property: 'focus_areas',
                            type: 'array',
                            items: new OA\Items(type: 'string', example: 'main-ideas')
                        ),
                    ]
                ),
                new OA\Property(
                    property: 'max_file_size_bytes',
                    type: 'integer',
                    description: 'Maximum size of a single upload, in bytes.',
                    example: 134217728
                ),
            ]
        )
    )]
    public function getCapabilities(): JsonResponse
    {
        return $this->json($this->capabilityService->getCapabilities());
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
        description: 'Returns active models grouped by capability (CHAT, IMAGE, SORT, etc.), restricted to models whose provider is available on this installation (API key / URL configured; Ollama models must be pulled). Admins can pass includeUnavailable=1 to also receive models of unconfigured providers, flagged via available/unavailableReason, e.g. to grey them out.',
        security: [['Bearer' => []]],
        tags: ['Configuration']
    )]
    #[OA\Parameter(
        name: 'includeUnavailable',
        description: 'Admin only (silently ignored otherwise): also return models whose provider is not configured, flagged with available=false.',
        in: 'query',
        required: false,
        schema: new OA\Schema(type: 'boolean', default: false)
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
                                    new OA\Property(property: 'id', type: 'integer', example: 324),
                                    new OA\Property(property: 'service', type: 'string', example: 'Groq'),
                                    new OA\Property(property: 'name', type: 'string', example: 'Qwen 3.6 27B'),
                                    new OA\Property(property: 'quality', type: 'integer', example: 9),
                                    new OA\Property(property: 'features', type: 'array', items: new OA\Items(type: 'string', example: 'reasoning')),
                                    new OA\Property(property: 'available', type: 'boolean', example: true, description: 'False only in the admin includeUnavailable view: the provider has no key/URL, or the Ollama model is not pulled.'),
                                    new OA\Property(property: 'unavailableReason', type: 'string', nullable: true, enum: ['provider_unavailable', 'not_pulled'], example: null),
                                ]
                            )
                        ),
                    ]
                ),
                new OA\Property(
                    property: 'providers',
                    type: 'array',
                    description: 'Availability of every registered AI provider on this installation (internal test provider excluded).',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'name', type: 'string', example: 'groq'),
                            new OA\Property(property: 'displayName', type: 'string', example: 'Groq'),
                            new OA\Property(property: 'available', type: 'boolean', example: true),
                            new OA\Property(property: 'requiresKey', type: 'boolean', description: 'True for cloud providers configured via a platform API key (the key wizard set); false for URL/local providers like Ollama or custom OpenAI-compatible endpoints.', example: true),
                        ]
                    )
                ),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function getModels(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Unavailable models are HIDDEN from regular users — they could not be
        // used anyway. Admin views request them explicitly to grey them out.
        $includeUnavailable = $request->query->getBoolean('includeUnavailable')
            && $this->isGranted('ROLE_ADMIN');

        $availability = $this->chatReadiness->providerAvailability();

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

            ['available' => $available, 'reason' => $unavailableReason] = $this->chatReadiness->modelAvailability($model->getService(), $model->getProviderId(), $availability);
            if (!$available && !$includeUnavailable) {
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
                'available' => $available,
                'unavailableReason' => $unavailableReason,
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

        $providers = [];
        foreach ($this->providerRegistry->getUniqueProviders() as $name => $provider) {
            $key = ModelCatalog::normalizeProvider((string) $name);
            if ('test' === $key) {
                continue;
            }
            $providers[] = [
                'name' => $key,
                'displayName' => $provider->getDisplayName(),
                'available' => $availability[$key] ?? false,
                'requiresKey' => ProviderKeyStore::isSupported($key),
            ];
        }
        usort($providers, static fn (array $a, array $b): int => strcasecmp($a['displayName'], $b['displayName']));

        return $this->json([
            'success' => true,
            'models' => $grouped,
            'providers' => $providers,
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
     * Removes stale per-user overrides and writes fresh ones that match the
     * catalog-recommended models, skipping any whose provider is not usable
     * here. Other users and the global (ownerId=0) row are unaffected.
     */
    #[Route('/models/defaults/reset', name: 'models_defaults_reset', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/config/models/defaults/reset',
        summary: 'Apply recommended model defaults to own configuration',
        description: 'Replaces all per-user DEFAULTMODEL overrides with the recommended defaults (from DefaultModelConfigSeeder) that are usable on this installation: a recommended model whose provider has no key is replaced by the first usable model for that capability, and nothing is written when no provider is usable at all. Does NOT modify global defaults — other users are unaffected. Returns the newly written defaults.',
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
     * Get the user's planner model selection (DEFAULTMODEL.PLAN).
     *
     * The planner (TaskPlanner) breaks complex requests into a multi-task DAG.
     * Its model resolves to the per-user DEFAULTMODEL.PLAN override, then the
     * global one, then falls back to the Sorting model (DEFAULTMODEL.SORT). This
     * endpoint exposes that selection so it is configurable in the UI instead of
     * only via direct SQL (#1143).
     */
    #[Route('/routing/planner-model', name: 'routing_planner_model_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/config/routing/planner-model',
        summary: 'Get the planner model selection',
        description: 'Returns the resolved planner model id (DEFAULTMODEL.PLAN, user override then global) and the Sorting model id it falls back to when no planner model is set.',
        security: [['Bearer' => []]],
        tags: ['Configuration']
    )]
    #[OA\Response(
        response: 200,
        description: 'Planner model selection',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'modelId', type: 'integer', nullable: true, description: 'Selected planner model id, or null when none is configured (falls back to the Sorting model)', example: 12),
                new OA\Property(property: 'fallbackModelId', type: 'integer', nullable: true, description: 'Sorting model id used when no planner model is set', example: 7),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function getPlannerModel(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $userId = $user->getId();

        $config = $this->configRepository->findOneBy([
            'ownerId' => $userId,
            'group' => 'DEFAULTMODEL',
            'setting' => 'PLAN',
        ]) ?? $this->configRepository->findOneBy([
            'ownerId' => 0,
            'group' => 'DEFAULTMODEL',
            'setting' => 'PLAN',
        ]);

        $modelId = null;
        if ($config) {
            $candidate = (int) $config->getValue();
            $model = $this->modelRepository->find($candidate);
            $modelId = ($model && 1 === $model->getActive()) ? $candidate : null;
        }

        return $this->json([
            'success' => true,
            'modelId' => $modelId,
            'fallbackModelId' => $this->modelConfigService->getDefaultModel('SORT', $userId),
        ]);
    }

    /**
     * Save (or clear) the user's planner model selection (DEFAULTMODEL.PLAN).
     *
     * A null `modelId` removes the per-user override so the planner reverts to
     * the global default / Sorting model fallback.
     */
    #[Route('/routing/planner-model', name: 'routing_planner_model_save', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/config/routing/planner-model',
        summary: 'Save the planner model selection',
        description: 'Writes the per-user DEFAULTMODEL.PLAN override. Pass `modelId: null` to clear the override and fall back to the Sorting model.',
        security: [['Bearer' => []]],
        tags: ['Configuration'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['modelId'],
                properties: [
                    new OA\Property(property: 'modelId', type: 'integer', nullable: true, description: 'Planner model id, or null to clear the override', example: 12),
                ]
            )
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Planner model saved',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'modelId', type: 'integer', nullable: true, example: 12),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid request body or model not available')]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function savePlannerModel(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !array_key_exists('modelId', $data)) {
            return $this->json(['error' => 'Invalid data'], Response::HTTP_BAD_REQUEST);
        }

        $userId = $user->getId();
        $raw = $data['modelId'];

        $existing = $this->configRepository->findOneBy([
            'ownerId' => $userId,
            'group' => 'DEFAULTMODEL',
            'setting' => 'PLAN',
        ]);

        // null clears the per-user override → revert to the Sorting model fallback.
        if (null === $raw) {
            if ($existing) {
                $this->em->remove($existing);
                $this->em->flush();
            }

            return $this->json(['success' => true, 'modelId' => null]);
        }

        if (!is_int($raw) && !(is_string($raw) && ctype_digit($raw))) {
            return $this->json(['error' => 'Invalid model id'], Response::HTTP_BAD_REQUEST);
        }

        $modelId = (int) $raw;
        $model = $this->modelRepository->find($modelId);
        if (!$model || 1 !== $model->getActive()) {
            return $this->json(['error' => 'Model not found or inactive'], Response::HTTP_BAD_REQUEST);
        }

        $config = $existing;
        if (!$config) {
            $config = new Config();
            $config->setOwnerId($userId);
            $config->setGroup('DEFAULTMODEL');
            $config->setSetting('PLAN');
        }
        $config->setValue((string) $modelId);
        $this->em->persist($config);
        $this->em->flush();

        return $this->json(['success' => true, 'modelId' => $modelId]);
    }

    /**
     * Get the platform-wide summary model (DEFAULTMODEL.SUMMARIZE).
     *
     * The rolling conversation summary condenses the older turns of a long chat
     * on every affected turn, so it wants a small, fast model (the seeded
     * default is GPT-OSS-120B on Groq) rather than the answering model. Unlike
     * the planner selection this is deliberately global: the summary is part of
     * the server-side pipeline every user's chat runs through, and it also
     * backs widget titles and document summaries.
     */
    #[Route('/routing/summary-model', name: 'routing_summary_model_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/config/routing/summary-model',
        summary: 'Get the platform summary model',
        description: 'Admin only. Returns the global DEFAULTMODEL.SUMMARIZE selection and the Sorting model it falls back to when none is set.',
        security: [['Bearer' => []]],
        tags: ['Configuration']
    )]
    #[OA\Response(
        response: 200,
        description: 'Summary model selection',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'modelId', type: 'integer', nullable: true, description: 'Selected summary model id, or null when none is configured (falls back to the Sorting model)', example: 300),
                new OA\Property(property: 'fallbackModelId', type: 'integer', nullable: true, description: 'Sorting model id used when no summary model is set', example: 7),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function getSummaryModel(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        $config = $this->configRepository->findOneBy([
            'ownerId' => 0,
            'group' => 'DEFAULTMODEL',
            'setting' => 'SUMMARIZE',
        ]);

        $modelId = null;
        if ($config) {
            $candidate = (int) $config->getValue();
            $model = $this->modelRepository->find($candidate);
            $modelId = ($model && 1 === $model->getActive()) ? $candidate : null;
        }

        return $this->json([
            'success' => true,
            'modelId' => $modelId,
            'fallbackModelId' => $this->modelConfigService->getDefaultModel('SORT', 0),
        ]);
    }

    /**
     * Save (or clear) the platform-wide summary model (DEFAULTMODEL.SUMMARIZE).
     *
     * A null `modelId` removes the row so summarization reverts to the Sorting
     * model.
     */
    #[Route('/routing/summary-model', name: 'routing_summary_model_save', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/config/routing/summary-model',
        summary: 'Save the platform summary model',
        description: 'Admin only. Writes the global DEFAULTMODEL.SUMMARIZE row. Pass `modelId: null` to clear it and fall back to the Sorting model.',
        security: [['Bearer' => []]],
        tags: ['Configuration'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['modelId'],
                properties: [
                    new OA\Property(property: 'modelId', type: 'integer', nullable: true, description: 'Summary model id, or null to clear the selection', example: 300),
                ]
            )
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Summary model saved',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'modelId', type: 'integer', nullable: true, example: 300),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid request body or model not available')]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function saveSummaryModel(
        Request $request,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !array_key_exists('modelId', $data)) {
            return $this->json(['error' => 'Invalid data'], Response::HTTP_BAD_REQUEST);
        }

        $raw = $data['modelId'];
        $existing = $this->configRepository->findOneBy([
            'ownerId' => 0,
            'group' => 'DEFAULTMODEL',
            'setting' => 'SUMMARIZE',
        ]);

        if (null === $raw) {
            if ($existing) {
                $this->em->remove($existing);
                $this->em->flush();
            }

            return $this->json(['success' => true, 'modelId' => null]);
        }

        if (!is_int($raw) && !(is_string($raw) && ctype_digit($raw))) {
            return $this->json(['error' => 'Invalid model id'], Response::HTTP_BAD_REQUEST);
        }

        $modelId = (int) $raw;
        $model = $this->modelRepository->find($modelId);
        if (!$model || 1 !== $model->getActive()) {
            return $this->json(['error' => 'Model not found or inactive'], Response::HTTP_BAD_REQUEST);
        }

        $config = $existing;
        if (!$config) {
            $config = new Config();
            $config->setOwnerId(0);
            $config->setGroup('DEFAULTMODEL');
            $config->setSetting('SUMMARIZE');
        }
        $config->setValue((string) $modelId);
        $this->em->persist($config);
        $this->em->flush();

        return $this->json(['success' => true, 'modelId' => $modelId]);
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
                    description: 'Provider credential identifier (env var name) when the key is missing from both the DB store and the environment',
                    example: 'OPENAI_API_KEY'
                ),
                new OA\Property(
                    property: 'setup_instructions',
                    type: 'string',
                    nullable: true,
                    description: 'Short setup hint when `env_var` is present',
                    example: 'Configure OPENAI_API_KEY under Admin → AI Providers, or set it in the environment'
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

            try {
                $provider = $this->providerRegistry->getChatProvider('ollama');
                $modelName = $model->getProviderId() ?: $model->getName();

                // A reachable Ollama says nothing about THIS model: a stock
                // install has the server up with only the embedding model
                // pulled. Report the concrete model, not the server health.
                if (empty($provider->getStatus()['healthy'])) {
                    $message = 'Ollama server is not running';
                } elseif ($this->chatReadiness->isOllamaModelPulled($modelName)) {
                    $available = true;
                } else {
                    $message = sprintf('Ollama is running but the model "%s" is not downloaded yet', $modelName);
                }

                // Always provide install command for Ollama models
                $installCommand = "docker compose exec ollama ollama pull {$modelName}";
            } catch (\Exception $e) {
                $message = 'Ollama not available: '.$e->getMessage();
            }
        } elseif (null !== ($registeredProvider = $this->findProviderForModelService($service))) {
            // Prefer the provider's own availability (DB-backed ProviderKeyStore
            // and/or env bootstrap) over raw getenv() — a UI-saved key must
            // count as configured without requiring a matching .env entry.
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
            $response['setup_instructions'] = "Configure {$envVar} under Admin → AI Providers, or set it in the environment";
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
     * Decide whether a registered provider has the credentials needed to run.
     *
     * Prefer {@see ProviderMetadataInterface::isAvailable()} so DB-backed keys
     * from ProviderKeyStore (and env-bootstrap imports) count as configured.
     * Setting a model must succeed whenever a key exists in either the DB or
     * the environment. When unavailable, surface the first required credential
     * name as a setup hint for the admin toast.
     *
     * @return array{available: bool, message: ?string, env_var: ?string}
     */
    private function evaluateProviderRequiredConfiguration(ProviderMetadataInterface $provider, string $serviceLabel): array
    {
        // A key saved through the admin UI counts as configured even without a
        // matching .env entry — that is what the DB-backed key store is for.
        if ($provider->isAvailable()) {
            return ['available' => true, 'message' => null, 'env_var' => null];
        }

        // The provider's own check may only look at its primary key, while
        // getRequiredEnvVars() documents accepted alternatives (`any_of`) and
        // additional credentials. Honour those before declaring it unconfigured.
        $envVarHint = null;
        foreach ($provider->getRequiredEnvVars() as $envName => $meta) {
            if (false === ($meta['required'] ?? true)) {
                continue;
            }

            $candidates = isset($meta['any_of']) && \is_array($meta['any_of'])
                ? array_values(array_filter($meta['any_of'], 'is_string'))
                : [$envName];

            foreach ($candidates as $candidate) {
                if (SecretValueGuard::isUsable($this->envValue($candidate))) {
                    continue 2;
                }
            }

            $envVarHint ??= $candidates[0] ?? $envName;
        }

        if (null === $envVarHint) {
            return ['available' => true, 'message' => null, 'env_var' => null];
        }

        return [
            'available' => false,
            'message' => "Configuration not complete for {$serviceLabel}",
            'env_var' => $envVarHint,
        ];
    }

    private function envValue(string $name): ?string
    {
        $value = $_ENV[$name] ?? getenv($name);

        return \is_string($value) ? $value : null;
    }

    /**
     * Local AI (Ollama) model-download progress written by the backend entrypoint.
     * Authenticated users can poll this while AUTO_DOWNLOAD_MODELS pulls models.
     */
    #[Route('/local-ai/status', name: 'local_ai_download_status', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/config/local-ai/status',
        summary: 'Get local AI model download status',
        description: 'Returns progress of background Ollama model pulls started by the container entrypoint. When no download is running the status is idle/ready.',
        security: [['Bearer' => []]],
        tags: ['Configuration']
    )]
    #[OA\Response(
        response: 200,
        description: 'Local AI download status',
        content: new OA\JsonContent(
            required: ['status', 'currentModel', 'percent', 'message', 'models', 'updatedAt'],
            properties: [
                new OA\Property(property: 'status', type: 'string', enum: ['idle', 'waiting', 'downloading', 'ready', 'error'], example: 'downloading'),
                new OA\Property(property: 'currentModel', type: 'string', nullable: true, example: 'bge-m3'),
                new OA\Property(property: 'percent', type: 'integer', nullable: true, example: 43),
                new OA\Property(property: 'message', type: 'string', nullable: true, example: 'Downloading bge-m3'),
                new OA\Property(
                    property: 'models',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'name', type: 'string', example: 'bge-m3'),
                            new OA\Property(property: 'state', type: 'string', example: 'downloading'),
                            new OA\Property(property: 'percent', type: 'integer', nullable: true, example: 43),
                        ]
                    )
                ),
                new OA\Property(property: 'updatedAt', type: 'string', nullable: true, example: '2026-07-30T10:00:00Z'),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function getLocalAiDownloadStatus(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json($this->localAiDownloadStatus->getStatus());
    }

    /**
     * Get status of all features and services (Web Search, AI Providers, Processing Services, etc.)
     * Admin only (available in production builds).
     */
    #[Route('/features', name: 'features_status', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/config/features',
        summary: 'Get feature and service status (admin)',
        description: 'Returns the live status of all configured features, AI providers, and infrastructure services. **Admin only** (available in production).',
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
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function getFeaturesStatus(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$user->isAdmin()) {
            return $this->json(['error' => 'Admin access required'], Response::HTTP_FORBIDDEN);
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
            // Skip the synthetic test provider outside local APP_ENV=dev.
            if ('test' === $providerName && 'dev' !== ($_ENV['APP_ENV'] ?? 'prod')) {
                continue;
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
