<?php

namespace App\Controller;

use App\Entity\Config;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\AI\Service\ProviderRegistry;
use App\Service\Search\BraveSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use App\Entity\User;
use OpenApi\Attributes as OA;

#[Route('/api/v1/config', name: 'api_config_')]
#[OA\Tag(name: 'Configuration')]
class ConfigController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ConfigRepository $configRepository,
        private ModelRepository $modelRepository,
        private ProviderRegistry $providerRegistry,
        private BraveSearchService $braveSearchService
    ) {}

    /**
     * Get all available models (all active models for all capabilities)
     * User can choose ANY model for ANY capability (cross-capability usage)
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
                                    new OA\Property(property: 'features', type: 'array', items: new OA\Items(type: 'string', example: 'reasoning'))
                                ]
                            )
                        )
                    ]
                )
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
                'features' => $model->getFeatures()
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
            'ANALYZE' => []
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
            'models' => $grouped
        ]);
    }

    /**
     * Get current default model configuration for user
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
                'setting' => $capability
            ]);

            // Fall back to global config
            if (!$config) {
                $config = $this->configRepository->findOneBy([
                    'ownerId' => 0,
                    'group' => 'DEFAULTMODEL',
                    'setting' => $capability
                ]);
            }

            $defaults[$capability] = $config ? (int) $config->getValue() : null;
        }

        return $this->json([
            'success' => true,
            'defaults' => $defaults
        ]);
    }

    /**
     * Save default model configuration for user
     */
    #[Route('/models/defaults', name: 'models_defaults_save', methods: ['POST'])]
    public function saveDefaultModels(
        Request $request,
        #[CurrentUser] ?User $user
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
                    'error' => "Model {$modelId} not found"
                ], Response::HTTP_BAD_REQUEST);
            }
            
            // Check if model is active
            if ($model->getActive() !== 1) {
                return $this->json([
                    'error' => "Model {$modelId} is not active"
                ], Response::HTTP_BAD_REQUEST);
            }

            // Check if user-specific config exists
            $config = $this->configRepository->findOneBy([
                'ownerId' => $userId,
                'group' => 'DEFAULTMODEL',
                'setting' => $capability
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
            'message' => 'Default models saved successfully'
        ]);
    }

    /**
     * Check if a model is available/ready to use
     * 
     * @param int $modelId Model ID to check
     * @return JsonResponse {available: bool, provider_type: string, message?: string, install_command?: string}
     */
    #[Route('/models/{modelId}/check', name: 'models_check', methods: ['GET'])]
    public function checkModelAvailability(
        int $modelId,
        #[CurrentUser] ?User $user
    ): JsonResponse {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $model = $this->modelRepository->find($modelId);
        if (!$model) {
            return $this->json([
                'available' => false,
                'error' => 'Model not found'
            ], Response::HTTP_NOT_FOUND);
        }

        $service = strtolower($model->getService());
        $providerType = 'unknown';
        $available = false;
        $message = null;
        $installCommand = null;
        $envVar = null;

        // Determine provider type and check availability
        if ($service === 'ollama') {
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
                    $message = "Ollama server is not running";
                }
                
                // Always provide install command for Ollama models
                $installCommand = "docker compose exec ollama ollama pull {$modelName}";
            } catch (\Exception $e) {
                $message = "Ollama not available: " . $e->getMessage();
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
                'mistral' => ['MISTRAL_API_KEY']
            ];
            
            $envVars = $envVarMap[$service] ?? [];
            
            // Check if any of the env vars is set and not empty
            $available = false;
            foreach ($envVars as $envVar) {
                $apiKey = $_ENV[$envVar] ?? '';
                if (!empty($apiKey) && $apiKey !== 'your-api-key-here') {
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
            'service' => $service
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
     * Only available in development mode
     */
    #[Route('/features', name: 'features_status', methods: ['GET'])]
    public function getFeaturesStatus(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        // Only allow in development mode
        $env = $_ENV['APP_ENV'] ?? 'prod';
        if ($env !== 'dev') {
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
                    'hint' => 'Get your API key from https://api.search.brave.com/'
                ],
                'BRAVE_SEARCH_ENABLED' => [
                    'required' => true,
                    'set' => ($_ENV['BRAVE_SEARCH_ENABLED'] ?? 'false') === 'true',
                    'hint' => 'Set to "true" to enable web search'
                ]
            ]
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
                ? count($imageModels) . ' image generation model(s) available' 
                : 'No image generation models configured',
            'setup_required' => !$hasImageModels,
            'models_available' => count($imageModels)
        ];

        // Code Interpreter
        $features['code-interpreter'] = [
            'id' => 'code-interpreter',
            'category' => 'AI Features',
            'name' => 'Code Interpreter',
            'enabled' => true,
            'status' => 'active',
            'message' => 'Code interpreter is active',
            'setup_required' => false
        ];

        // ========== AI Providers ==========
        
        // Ollama
        $ollamaUrl = $_ENV['OLLAMA_BASE_URL'] ?? '';
        $ollamaHealthy = $this->checkServiceHealth($ollamaUrl . '/api/tags');
        $features['ollama'] = [
            'id' => 'ollama',
            'category' => 'AI Providers',
            'name' => 'Ollama (Local AI)',
            'enabled' => !empty($ollamaUrl),
            'status' => $ollamaHealthy ? 'healthy' : ($ollamaUrl ? 'unhealthy' : 'disabled'),
            'message' => $ollamaHealthy 
                ? 'Ollama is running and accessible' 
                : ($ollamaUrl ? 'Ollama service is not responding' : 'Ollama URL not configured'),
            'setup_required' => empty($ollamaUrl),
            'url' => $ollamaUrl
        ];

        // OpenAI
        $openaiKey = $_ENV['OPENAI_API_KEY'] ?? '';
        $features['openai'] = [
            'id' => 'openai',
            'category' => 'AI Providers',
            'name' => 'OpenAI',
            'enabled' => !empty($openaiKey),
            'status' => !empty($openaiKey) ? 'active' : 'disabled',
            'message' => !empty($openaiKey) 
                ? 'OpenAI API key configured' 
                : 'OpenAI API key not configured',
            'setup_required' => empty($openaiKey),
            'env_vars' => [
                'OPENAI_API_KEY' => [
                    'required' => true,
                    'set' => !empty($openaiKey),
                    'hint' => 'Get your API key from https://platform.openai.com/'
                ]
            ]
        ];

        // Anthropic (Claude)
        $anthropicKey = $_ENV['ANTHROPIC_API_KEY'] ?? '';
        $features['anthropic'] = [
            'id' => 'anthropic',
            'category' => 'AI Providers',
            'name' => 'Anthropic (Claude)',
            'enabled' => !empty($anthropicKey),
            'status' => !empty($anthropicKey) ? 'active' : 'disabled',
            'message' => !empty($anthropicKey) 
                ? 'Anthropic API key configured' 
                : 'Anthropic API key not configured',
            'setup_required' => empty($anthropicKey),
            'env_vars' => [
                'ANTHROPIC_API_KEY' => [
                    'required' => true,
                    'set' => !empty($anthropicKey),
                    'hint' => 'Get your API key from https://console.anthropic.com/'
                ]
            ]
        ];

        // ========== Processing Services ==========
        
        // Whisper (Speech-to-Text)
        $whisperUrl = $_ENV['WHISPER_API_URL'] ?? 'http://whisper:9000';
        $whisperHealthy = $this->checkServiceHealth($whisperUrl . '/health');
        $features['whisper'] = [
            'id' => 'whisper',
            'category' => 'Processing Services',
            'name' => 'Whisper (Speech-to-Text)',
            'enabled' => true,
            'status' => $whisperHealthy ? 'healthy' : 'unhealthy',
            'message' => $whisperHealthy 
                ? 'Whisper service is running' 
                : 'Whisper service is not responding',
            'setup_required' => false,
            'url' => $whisperUrl
        ];

        // Apache Tika (Document Processing)
        $tikaUrl = $_ENV['TIKA_URL'] ?? 'http://tika:9998';
        $tikaHealthy = $this->checkServiceHealth($tikaUrl . '/tika');
        $features['tika'] = [
            'id' => 'tika',
            'category' => 'Processing Services',
            'name' => 'Apache Tika (Document Processing)',
            'enabled' => true,
            'status' => $tikaHealthy ? 'healthy' : 'unhealthy',
            'message' => $tikaHealthy 
                ? 'Tika service is running and processing documents' 
                : 'Tika service is not responding',
            'setup_required' => false,
            'url' => $tikaUrl
        ];

        // ========== Infrastructure Services ==========
        
        // Redis (Cache & Queue)
        $redisHost = $_ENV['REDIS_HOST'] ?? 'redis';
        $redisPort = $_ENV['REDIS_PORT'] ?? 6379;
        $redisHealthy = false;
        
        if (extension_loaded('redis')) {
            try {
                $redis = new \Redis();
                $redisHealthy = @$redis->connect($redisHost, (int)$redisPort, 1);
                if ($redisHealthy) {
                    $redis->close();
                }
            } catch (\Exception $e) {
                $redisHealthy = false;
            }
        }
        
        $features['redis'] = [
            'id' => 'redis',
            'category' => 'Infrastructure',
            'name' => 'Redis (Cache & Queue)',
            'enabled' => extension_loaded('redis'),
            'status' => $redisHealthy ? 'healthy' : (extension_loaded('redis') ? 'unhealthy' : 'disabled'),
            'message' => $redisHealthy 
                ? 'Redis is running and accessible' 
                : (extension_loaded('redis') ? 'Redis is not responding' : 'Redis PHP extension not installed'),
            'setup_required' => !extension_loaded('redis'),
            'url' => "$redisHost:$redisPort"
        ];

        // Database (MariaDB)
        try {
            $this->em->getConnection()->executeQuery('SELECT 1');
            $dbHealthy = true;
        } catch (\Exception $e) {
            $dbHealthy = false;
        }
        
        $features['database'] = [
            'id' => 'database',
            'category' => 'Infrastructure',
            'name' => 'Database (MariaDB)',
            'enabled' => true,
            'status' => $dbHealthy ? 'healthy' : 'unhealthy',
            'message' => $dbHealthy 
                ? 'Database connection is active' 
                : 'Database connection failed',
            'setup_required' => false
        ];

        // Count ready services
        $totalServices = count($features);
        $healthyServices = count(array_filter($features, fn($f) => 
            in_array($f['status'], ['active', 'healthy'])
        ));

        return $this->json([
            'features' => $features,
            'summary' => [
                'total' => $totalServices,
                'healthy' => $healthyServices,
                'unhealthy' => $totalServices - $healthyServices,
                'all_ready' => $healthyServices === $totalServices
            ]
        ]);
    }

    /**
     * Check if a service is healthy by making a simple HTTP request
     */
    private function checkServiceHealth(string $url): bool
    {
        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 2,
                    'ignore_errors' => true
                ]
            ]);
            
            $response = @file_get_contents($url, false, $context);
            
            if ($response === false) {
                return false;
            }
            
            // Check HTTP response code
            if (isset($http_response_header[0])) {
                preg_match('/\d{3}/', $http_response_header[0], $matches);
                $statusCode = isset($matches[0]) ? (int)$matches[0] : 0;
                return $statusCode >= 200 && $statusCode < 500; // Accept 2xx, 3xx, 4xx (not 5xx)
            }
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}

