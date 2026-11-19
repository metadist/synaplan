<?php

declare(strict_types=1);

namespace App\AI\Messages\Tools;

use App\AI\Messages\GatewayEffortRules;
use App\AI\Messages\Mcp\McpToolCatalogAdapter;
use App\AI\Messages\MessagesEventEmitter;
use App\AI\Messages\MessagesTranslatorInterface;
use App\AI\Messages\MessagesUsage;
use App\Entity\User;
use App\Service\Mcp\McpClient;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\RateLimitService;
use App\Service\Tool\Execution\ToolInvoker;
use App\Service\Tool\Execution\ToolInvokerSlot;
use App\Service\Tool\Loop\AgentToolLoop;
use App\Service\Tool\Loop\ToolLoopLimits;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Agentic server-side tool loop for the Messages gateway.
 *
 * Injects the session-pinned catalog built by {@see GatewayToolCatalog}, calls
 * the translator, and on `stop_reason === tool_use` executes the tools Synaplan
 * owns — the user's custom HTTP tools, MCP tools via {@see McpClient}, and
 * Synaplan's built-ins such
 * as {@see WebSearchTool} — appends `tool_result` turns, and re-prompts until
 * end_turn, only client-owned tools remain, or bounds are hit. The rounds run
 * on {@see AgentToolLoop}; the wire sides are {@see MessagesCompleteAdapter}
 * and {@see MessagesStreamAdapter}.
 *
 * Tools the client owns are never executed here: they are relayed verbatim so
 * the client keeps driving its own loop.
 *
 * @phpstan-type LoopResult array{
 *     status: int,
 *     headers: array<string, list<string>>,
 *     body: array<string, mixed>|string|null,
 *     usage: MessagesUsage,
 *     iterations: int
 * }
 * @phpstan-type DispatchEntry array{kind: string, serverId: int, tool: string, annotations: array<string, mixed>}
 * @phpstan-type CatalogSnapshot array{
 *     tools: list<array{name: string, description: string, input_schema: array<string, mixed>}>,
 *     dispatch: array<string, DispatchEntry>,
 *     web_search?: string
 * }
 */
final readonly class GatewayToolLoop
{
    private ToolInvokerSlot $toolInvoker;

    private GatewayOwnedTools $owned;

    public function __construct(
        McpToolCatalogAdapter $catalogAdapter,
        GatewayOwnedDeps $deps,
        private MessagesGatewayConfig $config,
        RateLimitService $rateLimitService,
        LoggerInterface $logger,
        private AgentToolLoop $loop = new AgentToolLoop(),
    ) {
        $this->toolInvoker = new ToolInvokerSlot();
        $this->owned = new GatewayOwnedTools($catalogAdapter, $deps, $rateLimitService, $logger, $this->toolInvoker);
    }

    #[Required]
    public function setToolInvoker(ToolInvoker $toolInvoker): void
    {
        $this->toolInvoker->set($toolInvoker);
    }

    /**
     * Inject our tools at the front of `tools[]` (cache-prefix position 0) and
     * drop the client declarations we take over.
     *
     * A `web_search_*` entry is a request for the *API side* to search. When
     * Synaplan serves that request itself, forwarding the declaration as well
     * would either make the upstream run a second, duplicate search (Anthropic)
     * or be rejected as an unknown tool type (every other provider).
     *
     * @param array<string, mixed> $requestBody
     * @param CatalogSnapshot      $snapshot
     * @param list<string>         $replacedServerTools dispatch names that stand in for a server tool
     *
     * @return array<string, mixed>
     */
    public function injectTools(array $requestBody, array $snapshot, array $replacedServerTools = []): array
    {
        if ([] === $snapshot['tools']) {
            return $requestBody;
        }

        $requestBody = $this->stripServerTools($requestBody, $replacedServerTools);
        $clientTools = \is_array($requestBody['tools'] ?? null) ? array_values(array_filter($requestBody['tools'], '\is_array')) : [];
        $requestBody['tools'] = array_merge($snapshot['tools'], $clientTools);
        if (GatewayToolCatalog::WEB_SEARCH_SYNAPLAN === ($snapshot['web_search'] ?? null)) {
            $requestBody = GatewayEffortRules::apply($requestBody);
        }

        return $requestBody;
    }

    /**
     * Remove the client's server-tool declarations that Synaplan takes over, so
     * they never reach the upstream. Also used without a loop, when web search
     * is switched off entirely.
     *
     * @param array<string, mixed> $requestBody
     * @param list<string>         $replacedServerTools
     *
     * @return array<string, mixed>
     */
    public function stripServerTools(array $requestBody, array $replacedServerTools): array
    {
        if ([] === $replacedServerTools
            || !isset($requestBody['tools'])
            || !\is_array($requestBody['tools'])
        ) {
            return $requestBody;
        }

        $dropWebSearch = \in_array(WebSearchTool::NAME, $replacedServerTools, true);
        $dropCodeExecution = \in_array(CodeExecutionTool::NAME, $replacedServerTools, true);
        $kept = [];
        foreach ($requestBody['tools'] as $tool) {
            if (\is_array($tool) && $dropWebSearch && AnthropicServerTools::isWebSearch($tool)) {
                continue;
            }
            if (\is_array($tool) && $dropCodeExecution && AnthropicServerTools::isCodeExecution($tool)) {
                continue;
            }
            $kept[] = $tool;
        }

        $requestBody['tools'] = $kept;

        return $requestBody;
    }

    /**
     * Non-streaming tool loop.
     *
     * @param array<string, mixed> $requestBody
     * @param array<string, mixed> $translatorContext
     * @param CatalogSnapshot      $snapshot
     * @param list<string>         $replacedServerTools
     *
     * @return LoopResult
     */
    public function runComplete(
        array $requestBody,
        array $translatorContext,
        MessagesTranslatorInterface $translator,
        User $user,
        array $snapshot,
        array $replacedServerTools = [],
    ): array {
        $body = $this->injectTools($requestBody, $snapshot, $replacedServerTools);
        // Body is mutated — never forward a stale raw_body.
        $context = $translatorContext;
        unset($context['raw_body']);

        $adapter = new MessagesCompleteAdapter(
            $body,
            static fn (array $turnBody): array => $translator->complete($turnBody, $context),
            $user,
            $snapshot['dispatch'],
            $this->owned,
            $this->config,
            new WebFetchBudget($this->config->webFetchMaxPerTurn($user->getId())),
            MessagesToolTurn::assistantFrom($translatorContext),
        );
        $this->loop->run(ToolLoopLimits::defaults($this->config->mcpMaxIterations($user->getId())), $adapter);

        return $adapter->result();
    }

    /**
     * Streaming tool loop — one client-visible message, server-side MCP rounds
     * suppressed from the wire, keep-alive pings during tool execution.
     *
     * @param array<string, mixed>                                                    $requestBody
     * @param array<string, mixed>                                                    $translatorContext
     * @param CatalogSnapshot                                                         $snapshot
     * @param callable(string|array{event: string, data: array<string, mixed>}): void $emit
     * @param list<string>                                                            $replacedServerTools
     */
    public function runStream(
        array $requestBody,
        array $translatorContext,
        MessagesTranslatorInterface $translator,
        User $user,
        array $snapshot,
        callable $emit,
        array $replacedServerTools = [],
    ): MessagesUsage {
        $body = $this->injectTools($requestBody, $snapshot, $replacedServerTools);
        $context = $translatorContext;
        unset($context['raw_body']);
        $context['parsed_events'] = true;

        $adapter = new MessagesStreamAdapter(
            $body,
            static fn (array $turnBody, callable $onChunk): MessagesUsage => $translator->stream($turnBody, $context, $onChunk),
            new MessagesEventEmitter($emit),
            $user,
            $snapshot['dispatch'],
            $this->owned,
            $this->config,
            new WebFetchBudget($this->config->webFetchMaxPerTurn($user->getId())),
            MessagesToolTurn::assistantFrom($translatorContext),
        );
        $this->loop->run(ToolLoopLimits::defaults($this->config->mcpMaxIterations($user->getId())), $adapter);

        return $adapter->usage();
    }
}
