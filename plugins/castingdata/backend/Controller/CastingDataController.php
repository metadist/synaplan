<?php

declare(strict_types=1);

namespace Plugin\CastingData\Controller;

use App\Entity\User;
use App\Service\PluginDataService;
use OpenApi\Attributes as OA;
use Plugin\CastingData\Service\CastingApiClient;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Casting Data Plugin API Controller.
 *
 * Provides configuration and connection-test endpoints:
 *   GET  /config          — Read current config (API key masked)
 *   PUT  /config          — Save config (api_url, api_key, enabled)
 *   POST /test-connection — Test connectivity to the external casting API
 *
 * Routes: /api/v1/user/{userId}/plugins/castingdata/...
 */
#[Route('/api/v1/user/{userId}/plugins/castingdata', name: 'api_plugin_castingdata_')]
#[OA\Tag(name: 'Casting Data Plugin')]
class CastingDataController extends AbstractController
{
    private const PLUGIN_NAME = 'castingdata';

    public function __construct(
        private PluginDataService $pluginData,
        private CastingApiClient $apiClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Get current plugin configuration.
     */
    #[Route('/config', name: 'config_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/castingdata/config',
        summary: 'Get casting data plugin configuration',
        security: [['Bearer' => []]],
        tags: ['Casting Data Plugin']
    )]
    #[OA\Response(
        response: 200,
        description: 'Plugin configuration',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'api_url', type: 'string', example: 'https://backstage.castapp.pro'),
                new OA\Property(property: 'api_key_masked', type: 'string', example: 'sk_...****'),
                new OA\Property(property: 'enabled', type: 'boolean', example: true),
                new OA\Property(property: 'has_api_key', type: 'boolean', example: true),
            ]
        )
    )]
    public function getConfig(
        int $userId,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $config = $this->pluginData->get($userId, self::PLUGIN_NAME, 'config', 'settings');

        if (!$config) {
            return $this->json([
                'api_url' => '',
                'api_key_masked' => '',
                'enabled' => false,
                'has_api_key' => false,
            ]);
        }

        return $this->json([
            'api_url' => $config['api_url'] ?? '',
            'api_key_masked' => $this->maskApiKey($config['api_key'] ?? ''),
            'enabled' => (bool) ($config['enabled'] ?? false),
            'has_api_key' => !empty($config['api_key']),
        ]);
    }

    /**
     * Update plugin configuration.
     */
    #[Route('/config', name: 'config_update', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/user/{userId}/plugins/castingdata/config',
        summary: 'Update casting data plugin configuration',
        security: [['Bearer' => []]],
        tags: ['Casting Data Plugin']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'api_url', type: 'string', example: 'https://backstage.castapp.pro'),
                new OA\Property(property: 'api_key', type: 'string', example: 'your-api-key-here'),
                new OA\Property(property: 'enabled', type: 'boolean', example: true),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Configuration saved')]
    public function updateConfig(
        Request $request,
        int $userId,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $data = $request->toArray();
        } catch (\JsonException) {
            return $this->json(['error' => 'Invalid JSON payload'], Response::HTTP_BAD_REQUEST);
        }

        $existingConfig = $this->pluginData->get($userId, self::PLUGIN_NAME, 'config', 'settings') ?? [];

        $newConfig = [
            'api_url' => trim($data['api_url'] ?? $existingConfig['api_url'] ?? ''),
            'api_key' => $existingConfig['api_key'] ?? '',
            'enabled' => (bool) ($data['enabled'] ?? $existingConfig['enabled'] ?? false),
        ];

        // Only update api_key if a new one was provided (not empty, not masked)
        if (!empty($data['api_key']) && !str_contains($data['api_key'], '***')) {
            $newConfig['api_key'] = trim($data['api_key']);
        }

        $this->pluginData->set($userId, self::PLUGIN_NAME, 'config', 'settings', $newConfig);

        $this->logger->info('CastingData: Config updated', [
            'user_id' => $userId,
            'api_url' => $newConfig['api_url'],
            'enabled' => $newConfig['enabled'],
        ]);

        return $this->json([
            'success' => true,
            'api_url' => $newConfig['api_url'],
            'api_key_masked' => $this->maskApiKey($newConfig['api_key']),
            'enabled' => $newConfig['enabled'],
            'has_api_key' => !empty($newConfig['api_key']),
        ]);
    }

    /**
     * Test connection to the external casting API.
     */
    #[Route('/test-connection', name: 'test_connection', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/castingdata/test-connection',
        summary: 'Test connection to external casting API',
        security: [['Bearer' => []]],
        tags: ['Casting Data Plugin']
    )]
    #[OA\Response(
        response: 200,
        description: 'Connection test result',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
            ]
        )
    )]
    public function testConnection(
        int $userId,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $config = $this->pluginData->get($userId, self::PLUGIN_NAME, 'config', 'settings');

        if (!$config || empty($config['api_url']) || empty($config['api_key'])) {
            return $this->json([
                'success' => false,
                'message' => 'API URL and API Key must be configured first',
            ]);
        }

        $success = $this->apiClient->testConnection($userId);

        $this->logger->info('CastingData: Connection test', [
            'user_id' => $userId,
            'success' => $success,
            'api_url' => $config['api_url'],
        ]);

        return $this->json([
            'success' => $success,
            'message' => $success
                ? 'Connection successful'
                : 'Connection failed — check API URL and Key',
        ]);
    }

    /**
     * Mask an API key for safe display.
     */
    private function maskApiKey(string $key): string
    {
        if (empty($key)) {
            return '';
        }

        if (strlen($key) <= 8) {
            return str_repeat('*', strlen($key));
        }

        return substr($key, 0, 4).'...'.str_repeat('*', 4);
    }

    /**
     * Verify user has access to this plugin instance.
     */
    private function canAccessPlugin(?User $user, int $userId): bool
    {
        if (null === $user) {
            return false;
        }

        return $user->getId() === $userId;
    }
}
