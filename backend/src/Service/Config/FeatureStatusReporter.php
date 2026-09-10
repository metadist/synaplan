<?php

declare(strict_types=1);

namespace App\Service\Config;

use App\AI\Service\ProviderRegistry;
use App\Entity\User;
use App\Module\Probe\SidecarHealthProbeInterface;
use App\Plug\WebSearch\WebSearchGateway;
use App\Repository\ModelRepository;
use App\Service\Infrastructure\RedisService;
use App\Service\UserMemoryService;
use App\Service\WhisperService;
use Doctrine\DBAL\Connection;

/**
 * Builds the admin feature-status page (`GET /api/v1/config/features`).
 *
 * The payload shape is a contract with the frontend status view and is locked
 * by the snapshot test in tests/Unit/Service/Config; change the snapshot only
 * together with a deliberate contract change.
 */
final class FeatureStatusReporter
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ModelRepository $modelRepository,
        private readonly ProviderRegistry $providerRegistry,
        private readonly WebSearchGateway $webSearch,
        private readonly WhisperService $whisperService,
        private readonly UserMemoryService $memoryService,
        private readonly RedisService $redisService,
        private readonly SidecarHealthProbeInterface $probe,
    ) {
    }

    /**
     * @return array{features: array<string, array<string, mixed>>, summary: array{total: int, healthy: int, unhealthy: int, all_ready: bool}}
     */
    public function build(User $user): array
    {
        $features = [];

        // ========== AI Features ==========

        // Web Search (Brave API)
        $braveEnabled = $this->webSearch->isEnabled($user->getId());
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
        $tikaHealthy = $this->probe->isReachable($tikaUrl.'/tika', $tikaHttpUser, $tikaHttpPass);

        // Try to get Tika version
        $tikaVersion = '';
        if ($tikaHealthy) {
            $versionResponse = $this->probe->fetchText($tikaUrl.'/version', $tikaHttpUser, $tikaHttpPass);
            if ($versionResponse) {
                $tikaVersion = trim($versionResponse);
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

        // Docling (optional document extraction sidecar)
        $doclingUrl = trim((string) ($_ENV['DOCLING_BASE_URL'] ?? ''));
        $doclingConfigured = '' !== $doclingUrl && 'disabled' !== strtolower($doclingUrl);
        $doclingHealthy = $doclingConfigured && $this->probe->isReachable(rtrim($doclingUrl, '/').'/health');
        $features['docling'] = [
            'id' => 'docling',
            'category' => 'Processing Services',
            'name' => 'Docling',
            'enabled' => $doclingConfigured,
            'status' => $doclingConfigured ? ($doclingHealthy ? 'healthy' : 'unhealthy') : 'disabled',
            'message' => $doclingConfigured
                ? ($doclingHealthy
                    ? 'Document extraction sidecar is running'
                    : 'Docling is not responding at DOCLING_BASE_URL')
                : 'Optional. Set DOCLING_BASE_URL and start the docling Compose profile',
            'setup_required' => !$doclingConfigured,
            'url' => $doclingConfigured ? $doclingUrl : '',
        ];

        // Collabora CODE convert-to (optional office engine)
        $officeUrl = $this->officeConvertUrl();
        $officeConfigured = $this->isOfficeConvertConfigured();
        $officeHealthy = $officeConfigured && $this->probe->isReachable(rtrim($officeUrl, '/').'/hosting/capabilities');
        $features['office-convert'] = [
            'id' => 'office-convert',
            'category' => 'Processing Services',
            'name' => 'Office converter (Collabora)',
            'enabled' => $officeConfigured,
            'status' => $officeConfigured ? ($officeHealthy ? 'healthy' : 'unhealthy') : 'disabled',
            'message' => $officeConfigured
                ? ($officeHealthy
                    ? 'LibreOffice convert-to is ready'
                    : 'Collabora CODE is not responding at OFFICE_CONVERT_URL')
                : 'Optional. Set OFFICE_CONVERT_URL and start the office Compose profile',
            'setup_required' => !$officeConfigured,
            'url' => $officeConfigured ? $officeUrl : '',
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
            $this->connection->executeQuery('SELECT 1');
            $dbHealthy = true;

            // Get DB version
            $versionResult = $this->connection->executeQuery('SELECT VERSION()')->fetchOne();
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
            && $this->probe->isReachable($centrifugoBaseUrl.'/health');

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

        return [
            'features' => $features,
            'summary' => [
                'total' => $totalServices,
                'healthy' => $healthyServices,
                'unhealthy' => $totalServices - $healthyServices,
                'all_ready' => $healthyServices === $totalServices,
            ],
        ];
    }

    /**
     * Strip credentials from a DSN before exposing it (`redis://user:pass@host` → `redis://***@host`).
     */
    private function redactDsn(string $dsn): string
    {
        return (string) preg_replace('#://[^@/]*@#', '://***@', $dsn);
    }

    private function officeConvertUrl(): string
    {
        $fromEnv = $_ENV['OFFICE_CONVERT_URL'] ?? $_SERVER['OFFICE_CONVERT_URL'] ?? getenv('OFFICE_CONVERT_URL');

        return trim(is_string($fromEnv) ? $fromEnv : '');
    }

    private function isOfficeConvertConfigured(): bool
    {
        $url = $this->officeConvertUrl();

        return '' !== $url && 'disabled' !== $url;
    }
}
