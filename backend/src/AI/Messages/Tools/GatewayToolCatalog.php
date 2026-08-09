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
    /** The declaration was forwarded untouched for the upstream to run. */
    public const WEB_SEARCH_PASSTHROUGH = 'passthrough';
    /** The declaration was dropped before reaching the upstream. */
    public const WEB_SEARCH_OFF = 'off';
    /** The client never asked for web search. */
    public const WEB_SEARCH_NONE = 'none';

    /** Synaplan rewrote the turn onto a vision-capable catalog model. */
    public const VISION_SYNAPLAN = 'synaplan';
    /** Images left on the wire for the upstream. */
    public const VISION_PASSTHROUGH = 'passthrough';
    /** No image blocks in the request. */
    public const VISION_NONE = 'none';

    private const MCP_CACHE_PREFIX = 'messages_gateway_mcp_catalog_';
    private const MCP_CACHE_TTL = 7200;

    public function __construct(
        private McpToolCatalogAdapter $mcpCatalogAdapter,
        private WebSearchTool $webSearchTool,
        private AnalyzeImageTool $analyzeImageTool,
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
     * Resolve configured web search + vision native tools against what this
     * request and this install can actually do.
     *
     * @param array<string, mixed> $requestBody
     *
     * @return CatalogSnapshot
     */
    private function nativeTools(User $user, array $requestBody): array
    {
        $snapshot = $this->empty();
        $userId = (int) $user->getId();

        $this->appendWebSearch($snapshot, $userId, $requestBody);
        $this->appendAnalyzeImage($snapshot, $userId, $requestBody);

        return $snapshot;
    }

    /**
     * @param CatalogSnapshot      $snapshot
     * @param array<string, mixed> $requestBody
     */
    private function appendWebSearch(array &$snapshot, int $userId, array $requestBody): void
    {
        $mode = $this->config->webSearchMode($userId);
        $requested = $this->hasServerWebSearchDeclaration($requestBody);

        if ($this->hasClientToolNamed($requestBody, WebSearchTool::NAME)) {
            return;
        }

        if (MessagesGatewayConfig::WEB_SEARCH_OFF === $mode) {
            $snapshot['web_search'] = $requested ? self::WEB_SEARCH_OFF : self::WEB_SEARCH_NONE;

            return;
        }

        if (MessagesGatewayConfig::WEB_SEARCH_PASSTHROUGH === $mode) {
            $snapshot['web_search'] = $requested ? self::WEB_SEARCH_PASSTHROUGH : self::WEB_SEARCH_NONE;

            return;
        }

        $wantSynaplanSearch = MessagesGatewayConfig::WEB_SEARCH_SYNAPLAN === $mode || $requested;
        if (!$wantSynaplanSearch) {
            $snapshot['web_search'] = self::WEB_SEARCH_NONE;

            return;
        }

        if (!$this->webSearchTool->isAvailable()) {
            // No provider to run the search. Leave the Anthropic declaration on
            // the wire so api.anthropic.com can honour it. OpenAI/Gemini
            // translators drop server-tool declarations rather than forwarding
            // an unknown tool shape the provider would reject.
            $this->logger->info('GatewayToolCatalog: no search provider configured, forwarding web search to the upstream', [
                'mode' => $mode,
            ]);
            $snapshot['web_search'] = $requested ? self::WEB_SEARCH_PASSTHROUGH : self::WEB_SEARCH_NONE;

            return;
        }

        $this->addNativeTool($snapshot, $this->webSearchTool->declaration(), WebSearchTool::NAME);
        $snapshot['web_search'] = self::WEB_SEARCH_SYNAPLAN;
    }

    /**
     * Offer Synaplan OCR/describe whenever vision is available and the mode is
     * not `off`. Passthrough still offers the tool — it only refuses model rewrite.
     *
     * @param CatalogSnapshot      $snapshot
     * @param array<string, mixed> $requestBody
     */
    private function appendAnalyzeImage(array &$snapshot, int $userId, array $requestBody): void
    {
        $mode = $this->config->visionMode($userId);
        if (MessagesGatewayConfig::VISION_OFF === $mode) {
            return;
        }

        if ($this->hasClientToolNamed($requestBody, AnalyzeImageTool::NAME)) {
            return;
        }

        if (!$this->analyzeImageTool->isAvailable($userId)) {
            return;
        }

        $this->addNativeTool($snapshot, $this->analyzeImageTool->declaration(), AnalyzeImageTool::NAME);
    }

    /**
     * @param CatalogSnapshot                                                              $snapshot
     * @param array{name: string, description: string, input_schema: array<string, mixed>} $declaration
     */
    private function addNativeTool(array &$snapshot, array $declaration, string $name): void
    {
        if (isset($snapshot['dispatch'][$name])) {
            return;
        }

        $snapshot['tools'][] = $declaration;
        $snapshot['dispatch'][$name] = [
            'kind' => self::KIND_NATIVE,
            'serverId' => 0,
            'tool' => $name,
            'annotations' => ['readOnlyHint' => true],
        ];
    }

    /**
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
