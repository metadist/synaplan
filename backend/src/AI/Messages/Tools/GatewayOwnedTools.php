<?php

declare(strict_types=1);

namespace App\AI\Messages\Tools;

use App\AI\Messages\Mcp\McpToolCatalogAdapter;
use App\Entity\User;
use App\Service\RateLimitService;
use App\Service\Runtime\RuntimeProfile;
use App\Service\Tool\Execution\ToolInvokerSlot;
use App\Service\Tool\Loop\ToolCallPartition;
use App\Service\Tool\Policy\PolicyContext;
use Psr\Log\LoggerInterface;

/**
 * The tools of a Messages gateway turn that Synaplan runs itself: which
 * calls are ours, and one tool_result block per call. A failure is the tool
 * result, never a throw.
 *
 * @phpstan-import-type DispatchEntry from GatewayToolLoop
 */
final readonly class GatewayOwnedTools
{
    private const PING_INTERVAL_SECONDS = 15;

    public function __construct(
        private McpToolCatalogAdapter $catalogAdapter,
        private GatewayOwnedDeps $deps,
        private RateLimitService $rateLimitService,
        private LoggerInterface $logger,
        private ToolInvokerSlot $toolInvoker,
    ) {
    }

    /**
     * @param list<array<string, mixed>>   $toolUses
     * @param array<string, DispatchEntry> $dispatch
     * @param (callable(): void)|null      $ping
     *
     * @return list<array<string, mixed>> tool_result content blocks
     */
    public function execute(array $toolUses, array $dispatch, User $user, WebFetchBudget $pages, ?callable $ping = null, ?RuntimeProfile $assistant = null): array
    {
        $results = [];
        $lastPing = microtime(true);
        $owned = $this->owned();
        $afterRun = null !== $ping ? function () use ($ping, &$lastPing): void {
            $ping();
            $lastPing = microtime(true);
        } : null;

        foreach ($toolUses as $block) {
            if (null !== $ping && (microtime(true) - $lastPing) >= self::PING_INTERVAL_SECONDS) {
                $ping();
                $lastPing = microtime(true);
            }

            $toolUseId = (string) ($block['id'] ?? '');
            $name = (string) ($block['name'] ?? '');
            $arguments = $block['input'] ?? [];
            if (!\is_array($arguments)) {
                $arguments = [];
            }

            $rate = $this->rateLimitService->checkLimit($user, 'MESSAGES');
            if (!$rate['allowed']) {
                $results[] = $this->toolResultBlock(
                    $toolUseId,
                    'Rate limit exceeded; tool call skipped.',
                    isError: true,
                );
                continue;
            }

            $entry = $dispatch[$name] ?? null;
            if (null === $entry) {
                $parsed = $this->catalogAdapter->parseNamespaced($name);
                $results[] = $this->toolResultBlock(
                    $toolUseId,
                    null === $parsed
                        ? 'Unknown tool.'
                        : sprintf('Tool `%s` is not in the pinned session catalog.', $name),
                    isError: true,
                );
                continue;
            }

            if (GatewayToolCatalog::KIND_NATIVE === $entry['kind']) {
                $results[] = $owned->native($entry, $name, $arguments, $toolUseId, $user, $pages, $assistant, $afterRun);
                continue;
            }

            if (GatewayToolCatalog::KIND_CUSTOM === $entry['kind']) {
                $results[] = $owned->custom($entry, $name, $arguments, $toolUseId, $user, $afterRun);
                continue;
            }

            if ($this->catalogAdapter->isMutatingTool($entry['annotations']) && !$this->approvalsOn((int) $user->getId())) {
                $message = sprintf("the tool '%s' can modify data and is not allowed (read-only)", $entry['tool']);
                $this->deps->executionGate?->inspect(
                    (int) $user->getId(),
                    $name,
                    $arguments,
                    $user,
                    PolicyContext::Interactive,
                    'chat:0',
                    forcedRefusal: $message,
                );
                $results[] = $this->toolResultBlock($toolUseId, $message, isError: true);
                continue;
            }

            $server = $this->deps->servers->findByIdAndUser($entry['serverId'], (int) $user->getId());
            if (null === $server || !$server->isEnabled()) {
                $results[] = $this->toolResultBlock(
                    $toolUseId,
                    'MCP server is not available.',
                    isError: true,
                );
                continue;
            }

            $results[] = $owned->mcp($entry, $name, $arguments, $toolUseId, $user, $afterRun);
        }

        return $results;
    }

    private function owned(): GatewayOwnedCall
    {
        return new GatewayOwnedCall(
            $this->logger,
            $this->deps,
            $this->toolInvoker,
            $this->toolResultBlock(...),
            $this->recordMcpUsage(...),
            $this->recordNativeUsage(...),
        );
    }

    private function approvalsOn(int $userId): bool
    {
        return null !== $this->deps->toolsConfig && $this->deps->toolsConfig->isApprovalsEnabled($userId);
    }

    /**
     * @return array<string, mixed>
     */
    public function toolResultBlock(string $toolUseId, string $content, bool $isError = false): array
    {
        $block = [
            'type' => 'tool_result',
            'tool_use_id' => $toolUseId,
            'content' => $content,
        ];
        if ($isError) {
            $block['is_error'] = true;
        }

        return $block;
    }

    private function recordMcpUsage(User $user, int $serverId, string $tool, bool $error): void
    {
        $this->recordToolUsage($user, 'MCP_TOOL', 'mcp', sprintf('server:%d/%s', $serverId, $tool), $tool, $error);
    }

    private function recordNativeUsage(User $user, string $source, string $tool, string $query, bool $error): void
    {
        $this->recordToolUsage($user, $source, 'synaplan', 'tool:'.$tool, $query, $error);
    }

    /**
     * Ledger-only: tools must not consume the MESSAGES quota (issue #1878).
     * Usage statistics still need a BUSELOG row per tool call.
     */
    private function recordToolUsage(
        User $user,
        string $source,
        string $provider,
        string $model,
        string $inputText,
        bool $error,
    ): void {
        try {
            $this->rateLimitService->recordUsage($user, 'TOOLS', [
                'source' => $source,
                'provider' => $provider,
                'model' => $model,
                'input_text' => $inputText,
                'response_text' => $error ? 'error' : 'ok',
                'usage' => [
                    'prompt_tokens' => 0,
                    'completion_tokens' => 0,
                    'total_tokens' => 0,
                    'cached_tokens' => 0,
                    'cache_creation_tokens' => 0,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('GatewayToolLoop: recordUsage failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->getId(),
            ]);
        }
    }

    /**
     * A tool call is ours when it is in the catalog we injected, or when it
     * carries the `mcp__` prefix — a stale namespaced call from an earlier
     * catalog belongs to us too and is answered with an error tool_result
     * instead of being handed to a client that cannot execute it.
     *
     * @param list<array<string, mixed>>   $content
     * @param array<string, DispatchEntry> $dispatch
     */
    public function partition(array $content, array $dispatch): ToolCallPartition
    {
        $toolUses = [];
        foreach ($content as $block) {
            if ('tool_use' === ($block['type'] ?? '')) {
                $toolUses[] = $block;
            }
        }

        return ToolCallPartition::of($toolUses, function (array $block) use ($dispatch): ?bool {
            $name = (string) ($block['name'] ?? '');
            if (isset($dispatch[$name]) || $this->catalogAdapter->isOurs($name)) {
                return true;
            }

            // Anthropic owns web_fetch / unreplaced web_search — not a
            // client tool. Handing them to the desktop as `tool_use`
            // produced unpaired `srvtoolu_*` replays.
            return AnthropicServerTools::isPassthroughServerToolName($name) ? null : false;
        });
    }
}
