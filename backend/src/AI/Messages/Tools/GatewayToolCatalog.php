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
 * @phpstan-type CatalogSnapshot array{tools: list<GatewayTool>, dispatch: array<string, DispatchEntry>, web_search: string}
 */
final readonly class GatewayToolCatalog
{
    public const KIND_MCP = 'mcp';
    public const KIND_NATIVE = 'native';

    /** Synaplan executed the search itself. */
    public const WEB_SEARCH_SYNAPLAN = 'synaplan';
    /** The declaration was forwarded untouched for the upstream to honour. */
    public const WEB_SEARCH_PASSTHROUGH = 'passthrough';
    /** The declaration was dropped before reaching the upstream. */
    public const WEB_SEARCH_OFF = 'off';
    /** The client never asked for web search. */
    public const WEB_SEARCH_NONE = 'none';

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
        $native = $this->nativeTools($user, $requestBody);
        $tools = [];
        $dispatch = [];

        foreach ([$this->mcpTools($user, $sessionKey, $requestBody), $native] as $part) {
            foreach ($part['tools'] as $tool) {
                if (isset($dispatch[$tool['name']])) {
                    continue;
                }
                $tools[] = $tool;
                $dispatch[$tool['name']] = $part['dispatch'][$tool['name']];
            }
        }

        return ['tools' => $tools, 'dispatch' => $dispatch, 'web_search' => $native['web_search']];
    }

    /**
     * Client-declared tool entries the gateway must not forward as-is: the
     * Anthropic `web_search_*` server tool, either because Synaplan answers it
     * itself or because it was explicitly turned off.
     *
     * @param CatalogSnapshot $snapshot
     *
     * @return list<string> dispatch names that replace a server-tool declaration
     */
    public function replacedServerTools(array $snapshot): array
    {
        return \in_array($snapshot['web_search'], [self::WEB_SEARCH_SYNAPLAN, self::WEB_SEARCH_OFF], true)
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
            if (\is_array($cached) && isset($cached['tools'], $cached['dispatch'], $cached['web_search'])) {
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
     * Resolve the configured web search mode against what this request and this
     * install can actually do.
     *
     * `auto` only acts when the client asked for web search — injecting a tool
     * nobody requested would change the prompt of every gateway request. The
     * explicit `synaplan` mode is the way to offer search unconditionally.
     *
     * @param array<string, mixed> $requestBody
     *
     * @return CatalogSnapshot
     */
    private function nativeTools(User $user, array $requestBody): array
    {
        $snapshot = $this->empty();
        $mode = $this->config->webSearchMode((int) $user->getId());
        $requested = $this->hasServerWebSearchDeclaration($requestBody);

        // A client shipping its own runnable tool under this name owns it —
        // a second, identically named tool would make the model's call ambiguous.
        if ($this->hasClientToolNamed($requestBody, WebSearchTool::NAME)) {
            return $snapshot;
        }

        if (MessagesGatewayConfig::WEB_SEARCH_OFF === $mode) {
            $snapshot['web_search'] = $requested ? self::WEB_SEARCH_OFF : self::WEB_SEARCH_NONE;

            return $snapshot;
        }

        if (MessagesGatewayConfig::WEB_SEARCH_PASSTHROUGH === $mode) {
            $snapshot['web_search'] = $requested ? self::WEB_SEARCH_PASSTHROUGH : self::WEB_SEARCH_NONE;

            return $snapshot;
        }

        $wantSynaplanSearch = MessagesGatewayConfig::WEB_SEARCH_SYNAPLAN === $mode || $requested;
        if (!$wantSynaplanSearch) {
            $snapshot['web_search'] = self::WEB_SEARCH_NONE;

            return $snapshot;
        }

        if (!$this->webSearchTool->isAvailable()) {
            // Nothing to run. Forwarding the declaration is still the best
            // available outcome: api.anthropic.com can honour it, and any other
            // upstream ignores a tool type it does not know.
            $this->logger->info('GatewayToolCatalog: no search provider configured, forwarding web search to the upstream', [
                'mode' => $mode,
            ]);
            $snapshot['web_search'] = $requested ? self::WEB_SEARCH_PASSTHROUGH : self::WEB_SEARCH_NONE;

            return $snapshot;
        }

        $snapshot['tools'][] = $this->webSearchTool->declaration();
        $snapshot['dispatch'][WebSearchTool::NAME] = [
            'kind' => self::KIND_NATIVE,
            'serverId' => 0,
            'tool' => WebSearchTool::NAME,
            'annotations' => ['readOnlyHint' => true],
        ];
        $snapshot['web_search'] = self::WEB_SEARCH_SYNAPLAN;

        return $snapshot;
    }

    /**
     * Did the client ask the API side to search, Anthropic-style?
     *
     * @param array<string, mixed> $requestBody
     */
    private function hasServerWebSearchDeclaration(array $requestBody): bool
    {
        foreach ($this->clientTools($requestBody) as $tool) {
            if (AnthropicServerTools::isWebSearch($tool)) {
                return true;
            }
        }

        return false;
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
        return ['tools' => [], 'dispatch' => [], 'web_search' => self::WEB_SEARCH_NONE];
    }
}
