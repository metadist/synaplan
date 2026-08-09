<?php

declare(strict_types=1);

namespace App\AI\Messages\Tools;

use App\AI\Messages\Mcp\McpToolCatalogAdapter;
use App\Entity\User;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the set of tools Synaplan executes server-side for one Messages
 * gateway request: the user's MCP tools plus Synaplan's own built-in tools.
 *
 * The snapshot is session-pinned (deterministic order, cached per session) so
 * the upstream prompt prefix stays cacheable across the turns of a session.
 *
 * @phpstan-type GatewayTool array{name: string, description: string, input_schema: array<string, mixed>}
 * @phpstan-type DispatchEntry array{kind: string, serverId: int, tool: string, annotations: array<string, mixed>}
 * @phpstan-type CatalogSnapshot array{tools: list<GatewayTool>, dispatch: array<string, DispatchEntry>}
 */
final readonly class GatewayToolCatalog
{
    public const KIND_MCP = 'mcp';
    public const KIND_NATIVE = 'native';

    private const MCP_CACHE_PREFIX = 'messages_gateway_mcp_catalog_';
    private const MCP_CACHE_TTL = 7200;

    public function __construct(
        private McpToolCatalogAdapter $mcpCatalogAdapter,
        private WebSearchTool $webSearchTool,
        private MessagesGatewayConfig $config,
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $requestBody
     *
     * @return CatalogSnapshot
     */
    public function build(User $user, string $sessionKey, array $requestBody): array
    {
        $tools = [];
        $dispatch = [];

        foreach ([$this->mcpTools($user, $sessionKey, $requestBody), $this->nativeTools($user, $requestBody)] as $part) {
            foreach ($part['tools'] as $tool) {
                if (isset($dispatch[$tool['name']])) {
                    continue;
                }
                $tools[] = $tool;
                $dispatch[$tool['name']] = $part['dispatch'][$tool['name']];
            }
        }

        return ['tools' => $tools, 'dispatch' => $dispatch];
    }

    /**
     * Client-declared tool entries the gateway takes over and must therefore
     * not forward upstream — currently the Anthropic `web_search_*` server
     * tool, which only api.anthropic.com could execute.
     *
     * @param CatalogSnapshot $snapshot
     *
     * @return list<string> dispatch names that replace a server-tool declaration
     */
    public function replacedServerTools(array $snapshot): array
    {
        return isset($snapshot['dispatch'][WebSearchTool::NAME])
            ? [WebSearchTool::NAME]
            : [];
    }

    /**
     * @param array<string, mixed> $requestBody
     *
     * @return CatalogSnapshot
     */
    private function mcpTools(User $user, string $sessionKey, array $requestBody): array
    {
        $userId = (int) $user->getId();
        if (!$this->config->isMcpToolsEnabled($userId)) {
            return $this->empty();
        }

        if ($this->hasClientTools($requestBody) && !$this->config->allowMcpToolsWithClientTools($userId)) {
            $this->logger->debug('GatewayToolCatalog: MCP tools skipped (client supplied tools)');

            return $this->empty();
        }

        $cacheKey = self::MCP_CACHE_PREFIX.hash('sha256', $sessionKey.':'.$userId);
        $item = $this->cache->getItem($cacheKey);
        if ($item->isHit()) {
            $cached = $item->get();
            if (\is_array($cached) && isset($cached['tools'], $cached['dispatch'])) {
                /* @var CatalogSnapshot $cached */
                return $cached;
            }
        }

        $mcp = $this->mcpCatalogAdapter->toAnthropicTools($userId, includeMutating: false);
        $snapshot = $this->empty();
        foreach ($mcp['tools'] as $tool) {
            $snapshot['tools'][] = $tool;
            $snapshot['dispatch'][$tool['name']] = [
                'kind' => self::KIND_MCP,
                'serverId' => $mcp['dispatch'][$tool['name']]['serverId'],
                'tool' => $mcp['dispatch'][$tool['name']]['tool'],
                'annotations' => $mcp['dispatch'][$tool['name']]['annotations'],
            ];
        }

        $item->set($snapshot);
        $item->expiresAfter(self::MCP_CACHE_TTL);
        $this->cache->save($item);

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $requestBody
     *
     * @return CatalogSnapshot
     */
    private function nativeTools(User $user, array $requestBody): array
    {
        $snapshot = $this->empty();

        if (!$this->config->isWebSearchEnabled((int) $user->getId())) {
            return $snapshot;
        }

        if (!$this->webSearchTool->isAvailable()) {
            $this->logger->info('GatewayToolCatalog: web search enabled but no search provider is configured');

            return $snapshot;
        }

        // A client that ships its own tool under this name owns it — offering a
        // second, identically named tool would make the model's call ambiguous.
        if ($this->hasClientToolNamed($requestBody, WebSearchTool::NAME)) {
            return $snapshot;
        }

        $snapshot['tools'][] = $this->webSearchTool->declaration();
        $snapshot['dispatch'][WebSearchTool::NAME] = [
            'kind' => self::KIND_NATIVE,
            'serverId' => 0,
            'tool' => WebSearchTool::NAME,
            'annotations' => ['readOnlyHint' => true],
        ];

        return $snapshot;
    }

    /**
     * Client tools, excluding server-tool declarations — those are capability
     * requests aimed at the API side, not tools the client can execute.
     *
     * @param array<string, mixed> $requestBody
     */
    private function hasClientTools(array $requestBody): bool
    {
        foreach ($this->clientTools($requestBody) as $tool) {
            if (!AnthropicServerTools::isServerToolDeclaration($tool)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $requestBody
     */
    private function hasClientToolNamed(array $requestBody, string $name): bool
    {
        foreach ($this->clientTools($requestBody) as $tool) {
            if ($name === ($tool['name'] ?? null) && !AnthropicServerTools::isServerToolDeclaration($tool)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $requestBody
     *
     * @return list<array<string, mixed>>
     */
    private function clientTools(array $requestBody): array
    {
        if (!isset($requestBody['tools']) || !\is_array($requestBody['tools'])) {
            return [];
        }

        $tools = [];
        foreach ($requestBody['tools'] as $tool) {
            if (\is_array($tool)) {
                $tools[] = $tool;
            }
        }

        return $tools;
    }

    /**
     * @return CatalogSnapshot
     */
    private function empty(): array
    {
        return ['tools' => [], 'dispatch' => []];
    }
}
