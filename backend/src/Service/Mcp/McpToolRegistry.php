<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Entity\McpServerConfig;
use App\Repository\McpServerConfigRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Cached per-server tool discovery for the outbound MCP client (plan 09
 * §3.2). Feeds two consumers:
 *   - the Settings UI ("browse this server's tools"), and
 *   - the planner's dynamic `mcp_fetch` sub-catalog (Sprint 6), which is
 *     rendered on EVERY planned turn — hence the short-TTL cache so plan
 *     latency never includes a live `tools/list` round-trip.
 *
 * Discovery failures degrade to an empty tool list (logged); the Settings
 * "test connection" path uses {@see refresh()} to surface the real error.
 */
final readonly class McpToolRegistry
{
    /** Tool catalogs change rarely; five minutes keeps planning snappy. */
    private const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private McpClient $client,
        private McpServerConfigRepository $servers,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Cached tools of one server. Never throws — planning must not break on
     * an unreachable server.
     *
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>}>
     */
    public function toolsFor(McpServerConfig $server): array
    {
        try {
            return $this->cache->get($this->cacheKey($server), function (ItemInterface $item) use ($server): array {
                $item->expiresAfter(self::CACHE_TTL_SECONDS);

                return $this->client->listTools($server);
            });
        } catch (\Throwable $e) {
            $this->logger->warning('McpToolRegistry: tool discovery failed', [
                'server_id' => $server->getId(),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Live (uncached) discovery — Settings "test connection". Lets
     * {@see McpClientException} propagate so the UI can show the reason, and
     * primes the cache on success.
     *
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>}>
     */
    public function refresh(McpServerConfig $server): array
    {
        $tools = $this->client->listTools($server);

        $this->cache->delete($this->cacheKey($server));
        $this->cache->get($this->cacheKey($server), function (ItemInterface $item) use ($tools): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            return $tools;
        });

        return $tools;
    }

    /**
     * Every enabled server of a user with its cached tools — the shape the
     * planner sub-catalog renders from.
     *
     * @return list<array{server: McpServerConfig, tools: list<array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>}>}>
     */
    public function catalogForUser(int $userId): array
    {
        $catalog = [];
        foreach ($this->servers->findEnabledByUser($userId) as $server) {
            $catalog[] = ['server' => $server, 'tools' => $this->toolsFor($server)];
        }

        return $catalog;
    }

    private function cacheKey(McpServerConfig $server): string
    {
        // The URL/auth revision is part of the key so editing a server never
        // serves the previous endpoint's cached tools.
        return sprintf('mcp_tools_%d_%s', (int) $server->getId(), md5($server->getUrl().'|'.$server->getUpdated()));
    }
}
