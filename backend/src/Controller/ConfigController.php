<?php

namespace App\Controller;

use App\AI\Service\ProviderRegistry;
use App\Entity\Config;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
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
        private UserMemoryService $memoryService,
        #[Autowire('%env(string:default::QDRANT_SERVICE_URL)%')]
        private readonly string $qdrantServiceUrl,
    ) {
    }

    /**
     * Quick memory service availability check (lightweight, no full status)
     * Frontend calls this asynchronously after app load to check if memory service is reachable.
     */
    #[Route('/memory-service/check', name: 'memory_service_check', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/config/memory-service/check',
        summary: 'Check memory service availability',
        description: 'Quick check if memory microservice is reachable (called asynchronously)',
        tags: ['Configuration']
    )]
    #[OA\Response(
        response: 200,
        description: 'Memory service status',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'available', type: 'boolean', example: true),
                new OA\Property(property: 'configured', type: 'boolean', example: true),
            ]
        )
    )]
    public function checkMemoryService(): JsonResponse
    {
        $configured = '' !== trim($this->qdrantServiceUrl);
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
                        new OA\Property(property: 'memoryService', type: 'boolean', example: true, description: 'Memory microservice availability'),
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
                            example: '2.1.0',
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
            ]
        )
    )]
    public function getRuntimeConfig(#[CurrentUser] ?User $user): JsonResponse
    {
        $recaptchaEnabled = ($_ENV['RECAPTCHA_ENABLED'] ?? 'false') === 'true';
        $recaptchaSiteKey = $_ENV['RECAPTCHA_SITE_KEY'] ?? '';

        // Only send site key if reCAPTCHA is enabled and site key is configured
        $recaptchaConfig = [
            'enabled' => $recaptchaEnabled && !empty($recaptchaSiteKey) && 'your_site_key_here' !== $recaptchaSiteKey,
            'siteKey' => ($recaptchaEnabled && !empty($recaptchaSiteKey) && 'your_site_key_here' !== $recaptchaSiteKey) ? $recaptchaSiteKey : '',
        ];

        // Feature flags
        // IMPORTANT: memoryService check is SLOW (1s timeout), so we always report true here
        // Frontend will check availability asynchronously via /api/v1/config/features/status
        $features = [
            'help' => ($_ENV['FEATURE_HELP'] ?? 'false') === 'true',
            'memoryService' => !empty($_ENV['QDRANT_SERVICE_URL']), // Just check if configured, not if reachable
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

        // Build information for debugging deployments (minimal: version + internal IP only)
        $buildInfo = [
            'version' => $_ENV['APP_VERSION'] ?? '2.1.0',
            'ip' => $this->getInternalIp(),
        ];

        return $this->json([
            'recaptcha' => $recaptchaConfig,
            'features' => $features,
            'speech' => $speech,
            'plugins' => $plugins,
            'googleTag' => $googleTagConfig,
            'build' => $buildInfo,
        ]);
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

        // Get all active models sorted by quality
        $models = $this->modelRepository->findBy(['active' => 1], ['quality' => 'DESC', 'rating' => 'DESC']);

        // Build model list with tag information
        $modelList = [];
        foreach ($models as $model) {
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
            ];
        }

        // Group models by their appropriate capability based on tag
        // This allows proper filtering while still enabling cross-capability if needed
        $grouped = [
            'SORT' => [],
            'CHAT' => [],
            'VECTORIZE' => [],
            'PIC2TEXT' => [],
            'TEXT2PIC' => [],
            'TEXT2VID' => [],
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
                    break;
                case 'VIDEO':
                case 'TEXT2VID':
                    $grouped['TEXT2VID'][] = $model;
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
    public function getDefaultModels(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $userId = $user->getId();
        $capabilities = ['SORT', 'CHAT', 'VECTORIZE', 'PIC2TEXT', 'TEXT2PIC', 'TEXT2VID', 'SOUND2TEXT', 'TEXT2SOUND', 'ANALYZE'];

        $defaults = [];

        foreach ($capabilities as $capability) {
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

            $defaults[$capability] = $config ? (int) $config->getValue() : null;
        }

        return $this->json([
            'success' => true,
            'defaults' => $defaults,
        ]);
    }

    /**
     * Save default model configuration for user.
     */
    #[Route('/models/defaults', name: 'models_defaults_save', methods: ['POST'])]
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

        $userId = $user->getId();
        $validCapabilities = ['SORT', 'CHAT', 'VECTORIZE', 'PIC2TEXT', 'TEXT2PIC', 'TEXT2VID', 'SOUND2TEXT', 'TEXT2SOUND', 'ANALYZE'];

        foreach ($data['defaults'] as $capability => $modelId) {
            if (!in_array($capability, $validCapabilities)) {
                continue;
            }

            // Validate model exists - allow any active model for any capability (cross-capability)
            $model = $this->modelRepository->find($modelId);
            if (!$model) {
                return $this->json([
                    'error' => "Model {$modelId} not found",
                ], Response::HTTP_BAD_REQUEST);
            }

            // Check if model is active
            if (1 !== $model->getActive()) {
                return $this->json([
                    'error' => "Model {$modelId} is not active",
                ], Response::HTTP_BAD_REQUEST);
            }

            // Check if user-specific config exists
            $config = $this->configRepository->findOneBy([
                'ownerId' => $userId,
                'group' => 'DEFAULTMODEL',
                'setting' => $capability,
            ]);

            if (!$config) {
                // Create new user-specific config
                $config = new Config();
                $config->setOwnerId($userId);
                $config->setGroup('DEFAULTMODEL');
                $config->setSetting($capability);
            }

            $config->setValue((string) $modelId);
            $this->em->persist($config);
        }

        $this->em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Default models saved successfully',
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
        } elseif (in_array($service, ['openai', 'anthropic', 'groq', 'gemini', 'google', 'mistral'])) {
            $providerType = 'external';

            // Check if API key is configured
            $envVarMap = [
                'openai' => ['OPENAI_API_KEY'],
                'anthropic' => ['ANTHROPIC_API_KEY'],
                'groq' => ['GROQ_API_KEY'],
                'gemini' => ['GEMINI_API_KEY', 'GOOGLE_GEMINI_API_KEY', 'GOOGLE_API_KEY'], // Support multiple key names
                'google' => ['GOOGLE_API_KEY', 'GOOGLE_GEMINI_API_KEY', 'GEMINI_API_KEY'], // Support multiple key names
                'mistral' => ['MISTRAL_API_KEY'],
            ];

            $envVars = $envVarMap[$service] ?? [];

            // Check if any of the env vars is set and not empty
            $available = false;
            foreach ($envVars as $envVar) {
                $apiKey = $_ENV[$envVar] ?? '';
                if (!empty($apiKey) && 'your-api-key-here' !== $apiKey) {
                    $available = true;
                    break;
                }
            }

            if (!$available) {
                $message = "API key not configured for {$service}";
                $envVar = $envVars[0] ?? null; // Use first one for setup instructions
            }
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
            $response['setup_instructions'] = "Add {$envVar}=your-api-key to your .env.local file";
        }

        return $this->json($response);
    }

    /**
     * Get status of all features and services (Web Search, AI Providers, Processing Services, etc.)
     * Only available in development mode.
     */
    #[Route('/features', name: 'features_status', methods: ['GET'])]
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
        $tikaHealthy = $this->checkServiceHealth($tikaUrl.'/tika');

        // Try to get Tika version
        $tikaVersion = '';
        if ($tikaHealthy) {
            try {
                $versionResponse = @file_get_contents($tikaUrl.'/version', false, stream_context_create([
                    'http' => ['timeout' => 2],
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

        // Memory Microservice (Qdrant) - User memories with vector search
        $qdrantUrl = $_ENV['QDRANT_SERVICE_URL'] ?? '';
        $qdrantApiKey = $_ENV['QDRANT_SERVICE_API_KEY'] ?? '';
        $memoryServiceAvailable = $this->memoryService->isAvailable();

        // Build status message and get service info
        $memoryMessage = '';
        $memoryWarnings = [];
        $memoryVersion = 'unknown';
        $memoryStats = [];
        $memoryMetrics = [];
        $memoryUptime = 0;

        if ($memoryServiceAvailable) {
            try {
                // Get detailed health information from microservice
                $healthDetails = $this->memoryService->getQdrantClient()->getHealthDetails();
                $memoryVersion = $healthDetails['version'] ?? 'unknown';
                $memoryStats = $healthDetails['qdrant'] ?? [];
                $memoryMetrics = $healthDetails['metrics'] ?? [];
                $memoryUptime = $healthDetails['uptime_seconds'] ?? 0;

                $memoryMessage = sprintf(
                    'Memory service is ready (uptime: %s)',
                    $this->formatUptime($memoryUptime)
                );

                // Check if API key is set (optional but recommended)
                if (empty($qdrantApiKey) || 'changeme-in-production' === $qdrantApiKey) {
                    $memoryWarnings[] = 'No API key configured - service is exposed without authentication';
                }

                // Check metrics for potential issues
                $requestsTotal = $memoryMetrics['requests_total'] ?? 0;
                $requestsFailed = $memoryMetrics['requests_failed'] ?? 0;
                if ($requestsTotal > 0 && $requestsFailed > 0) {
                    $errorRate = ($requestsFailed / $requestsTotal) * 100;
                    if ($errorRate > 5) {
                        $memoryWarnings[] = sprintf('High error rate: %.2f%% of requests failing', $errorRate);
                    }
                }
            } catch (\Throwable $e) {
                $memoryMessage = 'Memory service available but health check failed';
                $memoryWarnings[] = $e->getMessage();
            }
        } else {
            if (empty($qdrantUrl) || 'http://' === $qdrantUrl || 'https://' === $qdrantUrl) {
                $memoryMessage = 'Memory microservice URL not configured';
            } else {
                $memoryMessage = 'Memory microservice not reachable at configured URL';
            }
        }

        $features['memory-service'] = [
            'id' => 'memory-service',
            'category' => 'Processing Services',
            'name' => 'Memory Microservice (Qdrant)',
            'enabled' => $memoryServiceAvailable,
            'status' => $memoryServiceAvailable ? 'healthy' : 'unhealthy',
            'message' => $memoryMessage,
            'warnings' => $memoryWarnings,
            'setup_required' => !$memoryServiceAvailable,
            'url' => $qdrantUrl ?: 'not configured',
            'version' => $memoryVersion,
            'uptime_seconds' => $memoryUptime,
            'stats' => $memoryStats,
            'metrics' => $memoryMetrics,
            'env_vars' => [
                'QDRANT_SERVICE_URL' => [
                    'required' => true,
                    'set' => !empty($qdrantUrl) && 'http://' !== $qdrantUrl && 'https://' !== $qdrantUrl,
                    'hint' => 'URL to Qdrant microservice (e.g., http://qdrant-service:8090)',
                    'example' => 'http://qdrant-service:8090',
                ],
                'QDRANT_SERVICE_API_KEY' => [
                    'required' => false,
                    'set' => !empty($qdrantApiKey) && 'changeme-in-production' !== $qdrantApiKey,
                    'hint' => 'Optional API key for securing the Qdrant service (recommended in production)',
                    'warning' => 'Production deployments should use an API key for security',
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
     * Check if a service is healthy by making a simple HTTP request.
     */
    private function checkServiceHealth(string $url): bool
    {
        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 2,
                    'ignore_errors' => true,
                ],
            ]);

            $response = @file_get_contents($url, false, $context);

            if (false === $response) {
                return false;
            }

            // Check HTTP response code
            if (isset($http_response_header[0])) {
                preg_match('/\d{3}/', $http_response_header[0], $matches);
                $statusCode = isset($matches[0]) ? (int) $matches[0] : 0;

                return $statusCode >= 200 && $statusCode < 500; // Accept 2xx, 3xx, 4xx (not 5xx)
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Format uptime in seconds to human-readable string.
     */
    private function formatUptime(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        if ($seconds < 3600) {
            $minutes = floor($seconds / 60);

            return "{$minutes}m";
        }

        if ($seconds < 86400) {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);

            return "{$hours}h {$minutes}m";
        }

        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);

        return "{$days}d {$hours}h";
    }
}
