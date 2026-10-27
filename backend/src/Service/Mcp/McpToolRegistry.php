<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Entity\McpServerConfig;
use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\Notion\NotionSharing;
use App\Service\Mcp\Notion\NotionToolHints;
use Psr\Cache\CacheItemPoolInterface;
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
        private NotionToolHints $notionHints = new NotionToolHints(),
        private ?NotionSharing $notionSharing = null,
    ) {
    }

    /**
     * The organisation's shared Notion for a person without their own Notion
     * connection. Read-only: only `mcp_fetch` resolves it.
     *
     * @return list<array{server: McpServerConfig, tools: list<array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>}>}>
     */
    public function sharedCatalogFor(int $userId): array
    {
        $server = $this->sharedNotion($userId);

        return null === $server ? [] : [['server' => $server, 'tools' => $this->toolsFor($server)]];
    }

    public function sharedServer(int $serverId, int $userId): ?McpServerConfig
    {
        $server = $this->sharedNotion($userId);

        return null !== $server && $server->getId() === $serverId ? $server : null;
    }

    private function sharedNotion(int $userId): ?McpServerConfig
    {
        if (null === $this->notionSharing || $userId <= 0) {
            return null;
        }
        foreach ($this->servers->findEnabledByUser($userId) as $own) {
            if (NotionToolHints::isNotion($own->getUrl())) {
                return null;
            }
        }

        return $this->notionSharing->sharedServer($userId);
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
            $tools = $this->cache->get($this->cacheKey($server), function (ItemInterface $item) use ($server): array {
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

        return $this->notionHints->apply($server, $this->toolList($tools));
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

        return $this->notionHints->apply($server, $tools);
    }

    /**
     * Tools of one server ONLY when discovery already ran and is still cached
     * — never a network round-trip. Null when nothing is cached (or the pool
     * cannot be inspected). For callers on the sorter's critical path that
     * may hint at tool names but must not pay for discovery.
     *
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>}>|null
     */
    public function cachedToolsFor(McpServerConfig $server): ?array
    {
        if (!$this->cache instanceof CacheItemPoolInterface) {
            return null;
        }

        try {
            $item = $this->cache->getItem($this->cacheKey($server));
        } catch (\Throwable) {
            return null;
        }
        if (!$item->isHit()) {
            return null;
        }
        $tools = $item->get();

        return is_array($tools) ? $this->notionHints->apply($server, $this->toolList($tools)) : null;
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

    /**
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>}>
     */
    private function toolList(mixed $tools): array
    {
        if (!is_array($tools)) {
            return [];
        }

        $list = [];
        foreach ($tools as $tool) {
            if (!is_array($tool) || !is_string($tool['name'] ?? null)) {
                continue;
            }
            $list[] = [
                'name' => $tool['name'],
                'description' => is_string($tool['description'] ?? null) ? $tool['description'] : '',
                'inputSchema' => is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : [],
                'annotations' => is_array($tool['annotations'] ?? null) ? $tool['annotations'] : [],
            ];
        }

        return $list;
    }

    private function cacheKey(McpServerConfig $server): string
    {
        // The URL/auth revision is part of the key so editing a server never
        // serves the previous endpoint's cached tools.
        return sprintf('mcp_tools_%d_%s', (int) $server->getId(), md5($server->getUrl().'|'.$server->getUpdated()));
    }
}
