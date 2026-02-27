<?php

declare(strict_types=1);

namespace Plugin\CastingData\Service;

use App\Service\PluginDataService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Generic HTTP client for external casting platform APIs.
 *
 * Supports any platform that implements the standard endpoints:
 *   GET /api/v1/productions?search=&limit=
 *   GET /api/v1/productions/{id}
 *   GET /api/v1/auditions?production_id=&search=
 *   GET /api/v1/auditions/{id}
 *
 * Config (api_url, api_key) is loaded per-user from PluginDataService.
 */
final readonly class CastingApiClient
{
    private const PLUGIN_NAME = 'castingdata';
    private const TIMEOUT = 8;

    public function __construct(
        private HttpClientInterface $httpClient,
        private PluginDataService $pluginData,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Search productions by query string.
     *
     * @return array<int, array<string, mixed>> Production list
     */
    public function searchProductions(int $userId, string $query, int $limit = 5): array
    {
        return $this->request($userId, 'GET', '/api/v1/productions', [
            'search' => $query,
            'limit' => $limit,
        ]);
    }

    /**
     * Get production details including roles.
     *
     * @return array<string, mixed> Single production or empty array
     */
    public function getProduction(int $userId, int $id): array
    {
        return $this->request($userId, 'GET', sprintf('/api/v1/productions/%d', $id));
    }

    /**
     * Search active auditions.
     *
     * @return array<int, array<string, mixed>> Audition list
     */
    public function searchAuditions(int $userId, ?int $productionId = null, ?string $query = null): array
    {
        $params = [];
        if (null !== $productionId) {
            $params['production_id'] = $productionId;
        }
        if (null !== $query) {
            $params['search'] = $query;
        }

        return $this->request($userId, 'GET', '/api/v1/auditions', $params);
    }

    /**
     * Get audition details including requirements.
     *
     * @return array<string, mixed> Single audition or empty array
     */
    public function getAudition(int $userId, int $id): array
    {
        return $this->request($userId, 'GET', sprintf('/api/v1/auditions/%d', $id));
    }

    /**
     * Test connection to the external API.
     *
     * Issues a direct HTTP request (bypassing the error-swallowing request() method)
     * to verify that the API is reachable and responds with valid data.
     */
    public function testConnection(int $userId): bool
    {
        $config = $this->getConfig($userId);

        if (!$config || empty($config['api_url']) || empty($config['api_key'])) {
            return false;
        }

        $url = rtrim($config['api_url'], '/').'/api/v1/productions';

        try {
            $response = $this->httpClient->request('GET', $url, [
                'query' => ['limit' => 1],
                'headers' => [
                    'Authorization' => 'Bearer '.$config['api_key'],
                    'Accept' => 'application/json',
                ],
                'timeout' => self::TIMEOUT,
            ]);

            return $response->getStatusCode() < 400;
        } catch (\Throwable $e) {
            $this->logger->debug('CastingApiClient: Connection test failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get the stored configuration for a user.
     *
     * @return array{api_url: string, api_key: string, enabled: bool}|null
     */
    public function getConfig(int $userId): ?array
    {
        return $this->pluginData->get($userId, self::PLUGIN_NAME, 'config', 'settings');
    }

    /**
     * Execute an HTTP request against the external casting API.
     *
     * @param array<string, mixed> $queryParams
     *
     * @return array<string, mixed>
     */
    private function request(int $userId, string $method, string $path, array $queryParams = []): array
    {
        $config = $this->getConfig($userId);

        if (!$config || empty($config['api_url']) || empty($config['api_key'])) {
            $this->logger->debug('CastingApiClient: No config for user', ['user_id' => $userId]);

            return [];
        }

        $baseUrl = rtrim($config['api_url'], '/');
        $url = $baseUrl.$path;

        try {
            $response = $this->httpClient->request($method, $url, [
                'query' => $queryParams,
                'headers' => [
                    'Authorization' => 'Bearer '.$config['api_key'],
                    'Accept' => 'application/json',
                ],
                'timeout' => self::TIMEOUT,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 400) {
                $this->logger->warning('CastingApiClient: API returned error', [
                    'url' => $url,
                    'status' => $statusCode,
                    'user_id' => $userId,
                ]);

                return [];
            }

            $data = $response->toArray(false);

            // Handle Laravel-style paginated responses: { "data": [...] }
            if (isset($data['data']) && is_array($data['data'])) {
                return $data['data'];
            }

            return $data;
        } catch (\Throwable $e) {
            $this->logger->warning('CastingApiClient: Request failed', [
                'url' => $url,
                'error' => $e->getMessage(),
                'user_id' => $userId,
            ]);

            return [];
        }
    }
}
