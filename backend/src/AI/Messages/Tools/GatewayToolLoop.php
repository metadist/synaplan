<?php

declare(strict_types=1);

namespace App\AI\Messages\Tools;

use App\AI\Messages\Mcp\McpToolCatalogAdapter;
use App\AI\Messages\MessagesEventEmitter;
use App\AI\Messages\MessagesTranslatorInterface;
use App\AI\Messages\MessagesUsage;
use App\Entity\User;
use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\McpClient;
use App\Service\Mcp\McpClientException;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\RateLimitService;
use Psr\Log\LoggerInterface;

/**
 * Agentic server-side tool loop for the Messages gateway.
 *
 * Injects the session-pinned catalog built by {@see GatewayToolCatalog}, calls
 * the translator, and on `stop_reason === tool_use` executes the tools Synaplan
 * owns — the user's MCP tools via {@see McpClient} and Synaplan's built-ins such
 * as {@see WebSearchTool} — appends `tool_result` turns, and re-prompts until
 * end_turn, client-owned tools appear, or bounds are hit.
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
 *     dispatch: array<string, DispatchEntry>
 * }
 */
final readonly class GatewayToolLoop
{
    private const MAX_TOOL_RESULT_CHARS = 12000;
    private const MAX_TOOLS_PER_TURN = 16;
    private const WALL_CLOCK_SECONDS = 240;
    private const PING_INTERVAL_SECONDS = 15;

    public function __construct(
        private McpToolCatalogAdapter $catalogAdapter,
        private WebSearchTool $webSearchTool,
        private AnalyzeImageTool $analyzeImageTool,
        private McpClient $mcpClient,
        private McpServerConfigRepository $servers,
        private MessagesGatewayConfig $config,
        private RateLimitService $rateLimitService,
        private LoggerInterface $logger,
    ) {
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
        $clientTools = [];
        if (isset($requestBody['tools']) && \is_array($requestBody['tools'])) {
            foreach ($requestBody['tools'] as $tool) {
                if (\is_array($tool)) {
                    $clientTools[] = $tool;
                }
            }
        }

        $requestBody['tools'] = array_merge($snapshot['tools'], $clientTools);

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
        $kept = [];
        foreach ($requestBody['tools'] as $tool) {
            if (\is_array($tool) && $dropWebSearch && AnthropicServerTools::isWebSearch($tool)) {
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

        $maxIterations = $this->config->mcpMaxIterations($user->getId());
        $deadline = microtime(true) + self::WALL_CLOCK_SECONDS;
        $totalUsage = new MessagesUsage();
        $iterations = 0;
        $lastHeaders = [];
        $lastStatus = 200;
        $lastBody = null;

        for ($i = 0; $i < $maxIterations; ++$i) {
            if (microtime(true) > $deadline) {
                break;
            }

            ++$iterations;
            $result = $translator->complete($body, $context);
            $lastStatus = $result['status'];
            $lastHeaders = $result['headers'];
            $lastBody = $result['body'];
            $totalUsage = $this->sumUsage($totalUsage, $result['usage']);

            if ($lastStatus >= 400 || !\is_array($lastBody)) {
                return [
                    'status' => $lastStatus,
                    'headers' => $lastHeaders,
                    'body' => $lastBody,
                    'usage' => $totalUsage,
                    'iterations' => $iterations,
                ];
            }

            $stopReason = \is_string($lastBody['stop_reason'] ?? null) ? $lastBody['stop_reason'] : null;
            if ('tool_use' !== $stopReason) {
                return [
                    'status' => $lastStatus,
                    'headers' => $lastHeaders,
                    'body' => $this->withUsage($lastBody, $totalUsage),
                    'usage' => $totalUsage->withStopReason($stopReason),
                    'iterations' => $iterations,
                ];
            }

            $content = $lastBody['content'] ?? [];
            if (!\is_array($content)) {
                break;
            }

            $partition = $this->partitionToolUses($content, $snapshot['dispatch']);
            if ([] !== $partition['client']) {
                // Mixed or client-owned — return verbatim; client owns the loop.
                return [
                    'status' => $lastStatus,
                    'headers' => $lastHeaders,
                    'body' => $this->withUsage($lastBody, $totalUsage),
                    'usage' => $totalUsage->withStopReason('tool_use'),
                    'iterations' => $iterations,
                ];
            }

            if ([] === $partition['ours']) {
                break;
            }

            $toolResults = $this->executeOurs($partition['ours'], $snapshot['dispatch'], $user);
            $body = $this->appendToolTurn($body, $content, $toolResults);
        }

        if (!\is_array($lastBody)) {
            $lastBody = [
                'type' => 'error',
                'error' => [
                    'type' => 'api_error',
                    'message' => 'Server-side tool loop exceeded iteration or wall-clock limits.',
                ],
            ];
            $lastStatus = 502;
        }

        return [
            'status' => $lastStatus,
            'headers' => $lastHeaders,
            'body' => $this->withUsage($lastBody, $totalUsage),
            'usage' => $totalUsage,
            'iterations' => $iterations,
        ];
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

        $emitter = new MessagesEventEmitter($emit);
        $maxIterations = $this->config->mcpMaxIterations($user->getId());
        $deadline = microtime(true) + self::WALL_CLOCK_SECONDS;
        $totalUsage = new MessagesUsage();
        $suppressNames = array_keys($snapshot['dispatch']);

        for ($i = 0; $i < $maxIterations; ++$i) {
            if (microtime(true) > $deadline) {
                break;
            }

            $emitter->resetTurnMapping();
            $turn = $this->collectStreamedTurn($translator, $body, $context, $emitter, $suppressNames, $snapshot['dispatch']);
            $totalUsage = $this->sumUsage($totalUsage, $turn['usage']);

            if ($turn['error']) {
                $emitter->ensureClosed();

                return $totalUsage;
            }

            if ('tool_use' !== $turn['stop_reason']) {
                // Final turn was already relayed with isFinalTurn=true once we
                // know — collectStreamedTurn uses a two-phase approach.
                $emitter->ensureClosed();

                return $totalUsage->withStopReason($turn['stop_reason']);
            }

            $partition = $this->partitionToolUses($turn['content'], $snapshot['dispatch']);
            if ([] !== $partition['client'] || [] === $partition['ours']) {
                // Client owns remaining tools — already streamed (suppress list
                // only hides ours). Close the message.
                $emitter->ensureClosed();

                return $totalUsage->withStopReason('tool_use');
            }

            $toolResults = $this->executeOurs(
                $partition['ours'],
                $snapshot['dispatch'],
                $user,
                ping: static function () use ($emitter): void {
                    $emitter->emitPing();
                },
            );
            $body = $this->appendToolTurn($body, $turn['content'], $toolResults);
        }

        $emitter->ensureClosed();

        return $totalUsage;
    }

    /**
     * Stream one upstream turn. Content blocks are relayed live (our MCP
     * tool_use blocks suppressed). message_delta / message_stop are held until
     * stop_reason is known so intermediate MCP rounds stay invisible.
     *
     * @param array<string, mixed>         $requestBody
     * @param array<string, mixed>         $context
     * @param list<string>                 $suppressNames
     * @param array<string, DispatchEntry> $dispatch
     *
     * @return array{
     *     content: list<array<string, mixed>>,
     *     stop_reason: string|null,
     *     usage: MessagesUsage,
     *     error: bool
     * }
     */
    private function collectStreamedTurn(
        MessagesTranslatorInterface $translator,
        array $requestBody,
        array $context,
        MessagesEventEmitter $emitter,
        array $suppressNames,
        array $dispatch,
    ): array {
        /** @var list<array{event: string, data: array<string, mixed>}> $tail */
        $tail = [];
        $content = [];
        /** @var array<int, array<string, mixed>> $blocksByIndex */
        $blocksByIndex = [];
        $stopReason = null;
        $inputTokens = 0;
        $outputTokens = 0;
        $cacheCreation = 0;
        $cacheCreation1h = 0;
        $cacheRead = 0;
        $error = false;

        $streamBody = $requestBody;
        $streamBody['stream'] = true;

        $translator->stream($streamBody, $context, function (string|array $chunk) use (
            &$tail,
            &$blocksByIndex,
            &$stopReason,
            &$inputTokens,
            &$outputTokens,
            &$cacheCreation,
            &$cacheCreation1h,
            &$cacheRead,
            &$error,
            $emitter,
            $suppressNames,
        ): void {
            if (\is_string($chunk)) {
                $error = true;

                return;
            }

            $event = (string) $chunk['event'];
            $data = $chunk['data'];
            $type = (string) ($data['type'] ?? $event);

            if ('error' === $type) {
                $error = true;
                $emitter->relay($event, $data, isFinalTurn: true, suppressToolNames: []);

                return;
            }

            if ('message_start' === $type) {
                $usage = $data['message']['usage'] ?? [];
                if (\is_array($usage)) {
                    $inputTokens = (int) ($usage['input_tokens'] ?? $inputTokens);
                    $cacheCreation = (int) ($usage['cache_creation_input_tokens'] ?? $cacheCreation);
                    $cacheCreation1h = MessagesUsage::extractCacheCreation1hTokens($usage);
                    $cacheRead = (int) ($usage['cache_read_input_tokens'] ?? $cacheRead);
                }
                // Emit immediately — suppressed on subsequent turns by the emitter.
                $emitter->relay($event, $data, isFinalTurn: false, suppressToolNames: $suppressNames);

                return;
            }

            if ('ping' === $type) {
                $emitter->relay($event, $data, isFinalTurn: false, suppressToolNames: $suppressNames);

                return;
            }

            if (str_starts_with($type, 'content_block_')) {
                if ('content_block_start' === $type) {
                    $idx = (int) ($data['index'] ?? 0);
                    $block = $data['content_block'] ?? [];
                    if (\is_array($block)) {
                        $blocksByIndex[$idx] = $block;
                        if ('tool_use' === ($block['type'] ?? '')) {
                            $blocksByIndex[$idx]['input'] ??= [];
                            $blocksByIndex[$idx]['_partial_json'] = '';
                        }
                    }
                }

                if ('content_block_delta' === $type) {
                    $idx = (int) ($data['index'] ?? 0);
                    $delta = $data['delta'] ?? [];
                    if (\is_array($delta) && isset($blocksByIndex[$idx])) {
                        if ('text_delta' === ($delta['type'] ?? '') && isset($delta['text'])) {
                            $blocksByIndex[$idx]['text'] = ((string) ($blocksByIndex[$idx]['text'] ?? '')).(string) $delta['text'];
                        }
                        if ('input_json_delta' === ($delta['type'] ?? '') && isset($delta['partial_json'])) {
                            $blocksByIndex[$idx]['_partial_json'] = ((string) ($blocksByIndex[$idx]['_partial_json'] ?? '')).(string) $delta['partial_json'];
                        }
                    }
                }

                if ('content_block_stop' === $type) {
                    $idx = (int) ($data['index'] ?? 0);
                    if (isset($blocksByIndex[$idx]['_partial_json'])) {
                        $raw = (string) $blocksByIndex[$idx]['_partial_json'];
                        unset($blocksByIndex[$idx]['_partial_json']);
                        if ('' !== $raw) {
                            $decoded = json_decode($raw, true);
                            $blocksByIndex[$idx]['input'] = \is_array($decoded) ? $decoded : [];
                        }
                    }
                }

                // Live-relay with suppress; if this turn later proves client-owned
                // tool_use, those blocks were already hidden — acceptable trade-off
                // (mixed-turn default is off; pure client tools aren't suppressed).
                $emitter->relay($event, $data, isFinalTurn: false, suppressToolNames: $suppressNames);

                return;
            }

            if ('message_delta' === $type) {
                $usage = $data['usage'] ?? [];
                if (\is_array($usage)) {
                    $outputTokens = (int) ($usage['output_tokens'] ?? $outputTokens);
                }
                $delta = $data['delta'] ?? [];
                if (\is_array($delta) && isset($delta['stop_reason']) && \is_string($delta['stop_reason'])) {
                    $stopReason = $delta['stop_reason'];
                }
                $tail[] = ['event' => $event, 'data' => $data];

                return;
            }

            if ('message_stop' === $type) {
                $tail[] = ['event' => $event, 'data' => $data];

                return;
            }

            $emitter->relay($event, $data, isFinalTurn: false, suppressToolNames: $suppressNames);
        });

        ksort($blocksByIndex, \SORT_NUMERIC);
        foreach ($blocksByIndex as $block) {
            unset($block['_partial_json']);
            $content[] = $block;
        }

        $isFinal = 'tool_use' !== $stopReason || $error;
        if (!$isFinal) {
            $partition = $this->partitionToolUses($content, $dispatch);
            if ([] !== $partition['client'] || [] === $partition['ours']) {
                $isFinal = true;
            }
        }

        foreach ($tail as $item) {
            $emitter->relay(
                $item['event'],
                $item['data'],
                isFinalTurn: $isFinal,
                suppressToolNames: $suppressNames,
            );
        }

        return [
            'content' => $content,
            'stop_reason' => $stopReason,
            'usage' => new MessagesUsage(
                inputTokens: $inputTokens,
                outputTokens: $outputTokens,
                cacheCreationTokens: $cacheCreation,
                cacheCreation1hTokens: $cacheCreation1h,
                cacheReadTokens: $cacheRead,
                stopReason: $stopReason,
            ),
            'error' => $error,
        ];
    }

    /**
     * A tool call is ours when it is in the catalog we injected, or when it
     * carries the `mcp__` prefix — a stale namespaced call from an earlier
     * catalog belongs to us too and is answered with an error tool_result
     * instead of being handed to a client that cannot execute it.
     *
     * @param list<array<string, mixed>>   $content
     * @param array<string, DispatchEntry> $dispatch
     *
     * @return array{ours: list<array<string, mixed>>, client: list<array<string, mixed>>}
     */
    private function partitionToolUses(array $content, array $dispatch): array
    {
        $ours = [];
        $client = [];
        foreach ($content as $block) {
            if ('tool_use' !== ($block['type'] ?? '')) {
                continue;
            }
            $name = (string) ($block['name'] ?? '');
            if (isset($dispatch[$name]) || $this->catalogAdapter->isOurs($name)) {
                $ours[] = $block;
            } else {
                $client[] = $block;
            }
        }

        return ['ours' => $ours, 'client' => $client];
    }

    /**
     * @param list<array<string, mixed>>   $toolUses
     * @param array<string, DispatchEntry> $dispatch
     * @param (callable(): void)|null      $ping
     *
     * @return list<array<string, mixed>> tool_result content blocks
     */
    private function executeOurs(array $toolUses, array $dispatch, User $user, ?callable $ping = null): array
    {
        if (count($toolUses) > self::MAX_TOOLS_PER_TURN) {
            $toolUses = array_slice($toolUses, 0, self::MAX_TOOLS_PER_TURN);
        }

        $results = [];
        $lastPing = microtime(true);

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
                        ? 'Unknown MCP tool.'
                        : sprintf('Tool `%s` is not in the pinned session catalog.', $name),
                    isError: true,
                );
                continue;
            }

            if (GatewayToolCatalog::KIND_NATIVE === $entry['kind']) {
                $results[] = $this->executeNative($entry['tool'], $arguments, $toolUseId, $user);
                if (null !== $ping) {
                    $ping();
                    $lastPing = microtime(true);
                }
                continue;
            }

            if ($this->catalogAdapter->isMutatingTool($entry['annotations'])) {
                $results[] = $this->toolResultBlock(
                    $toolUseId,
                    sprintf("the tool '%s' can modify data and is not allowed (read-only)", $entry['tool']),
                    isError: true,
                );
                $this->recordMcpUsage($user, $entry['serverId'], $entry['tool'], error: true);
                continue;
            }

            $server = $this->servers->findByIdAndUser($entry['serverId'], (int) $user->getId());
            if (null === $server || !$server->isEnabled()) {
                $results[] = $this->toolResultBlock(
                    $toolUseId,
                    'MCP server is not available.',
                    isError: true,
                );
                $this->recordMcpUsage($user, $entry['serverId'], $entry['tool'], error: true);
                continue;
            }

            try {
                $call = $this->mcpClient->callTool($server, $entry['tool'], $arguments);
                $text = $this->formatToolContent($call['content']);
                $isError = $call['isError'];
                $results[] = $this->toolResultBlock($toolUseId, $text, $isError);
                $this->recordMcpUsage($user, $entry['serverId'], $entry['tool'], error: $isError);
            } catch (McpClientException $e) {
                $this->logger->warning('GatewayToolLoop: MCP tool call failed', [
                    'server_id' => $entry['serverId'],
                    'tool' => $entry['tool'],
                    'error' => $e->getMessage(),
                ]);
                $results[] = $this->toolResultBlock(
                    $toolUseId,
                    'Tool call failed: '.$e->getMessage(),
                    isError: true,
                );
                $this->recordMcpUsage($user, $entry['serverId'], $entry['tool'], error: true);
            }

            if (null !== $ping) {
                $ping();
                $lastPing = microtime(true);
            }
        }

        return $results;
    }

    /**
     * Execute one of Synaplan's built-in tools.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> tool_result content block
     */
    private function executeNative(string $tool, array $arguments, string $toolUseId, User $user): array
    {
        if (WebSearchTool::NAME === $tool) {
            $result = $this->webSearchTool->execute($arguments);
            $this->recordNativeUsage($user, 'WEB_SEARCH', $tool, $result['query'], $result['isError']);

            return $this->toolResultBlock($toolUseId, $this->clampToolText($result['text']), $result['isError']);
        }

        if (AnalyzeImageTool::NAME === $tool) {
            $result = $this->analyzeImageTool->execute($arguments, $user->getId());
            $this->recordNativeUsage($user, 'VISION', $tool, $result['summary'], $result['isError']);

            return $this->toolResultBlock($toolUseId, $this->clampToolText($result['text']), $result['isError']);
        }

        return $this->toolResultBlock($toolUseId, sprintf('Unknown Synaplan tool `%s`.', $tool), isError: true);
    }

    /**
     * @param array<string, mixed>       $requestBody
     * @param list<array<string, mixed>> $assistantContent
     * @param list<array<string, mixed>> $toolResults
     *
     * @return array<string, mixed>
     */
    private function appendToolTurn(array $requestBody, array $assistantContent, array $toolResults): array
    {
        $messages = $requestBody['messages'] ?? [];
        if (!\is_array($messages)) {
            $messages = [];
        }

        $messages[] = [
            'role' => 'assistant',
            'content' => $assistantContent,
        ];
        $messages[] = [
            'role' => 'user',
            'content' => $toolResults,
        ];

        $requestBody['messages'] = $messages;

        return $requestBody;
    }

    /**
     * @return array<string, mixed>
     */
    private function toolResultBlock(string $toolUseId, string $content, bool $isError = false): array
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

    /**
     * @param list<array<string, mixed>> $content
     */
    private function formatToolContent(array $content): string
    {
        $parts = [];
        foreach ($content as $block) {
            $type = $block['type'] ?? null;
            if ('text' === $type && \is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            } elseif (\is_array($block['resource'] ?? null) && \is_string($block['resource']['text'] ?? null)) {
                $parts[] = $block['resource']['text'];
            } else {
                $encoded = json_encode($block, \JSON_INVALID_UTF8_SUBSTITUTE);
                if (false !== $encoded) {
                    $parts[] = $encoded;
                }
            }
        }

        return $this->clampToolText(trim(implode("\n\n", $parts)));
    }

    private function clampToolText(string $text): string
    {
        if (mb_strlen($text) > self::MAX_TOOL_RESULT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_TOOL_RESULT_CHARS).'…';
        }

        return '' !== $text ? $text : '(empty tool result)';
    }

    private function recordMcpUsage(User $user, int $serverId, string $tool, bool $error): void
    {
        $this->recordToolUsage($user, 'MCP_TOOL', 'mcp', sprintf('server:%d/%s', $serverId, $tool), $tool, $error);
    }

    private function recordNativeUsage(User $user, string $source, string $tool, string $query, bool $error): void
    {
        $this->recordToolUsage($user, $source, 'synaplan', 'tool:'.$tool, $query, $error);
    }

    private function recordToolUsage(
        User $user,
        string $source,
        string $provider,
        string $model,
        string $inputText,
        bool $error,
    ): void {
        try {
            $this->rateLimitService->recordUsage($user, 'MESSAGES', [
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

    private function sumUsage(MessagesUsage $a, MessagesUsage $b): MessagesUsage
    {
        return new MessagesUsage(
            inputTokens: $a->inputTokens + $b->inputTokens,
            outputTokens: $a->outputTokens + $b->outputTokens,
            cacheCreationTokens: $a->cacheCreationTokens + $b->cacheCreationTokens,
            cacheCreation1hTokens: $a->cacheCreation1hTokens + $b->cacheCreation1hTokens,
            cacheReadTokens: $a->cacheReadTokens + $b->cacheReadTokens,
            stopReason: $b->stopReason ?? $a->stopReason,
        );
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function withUsage(array $body, MessagesUsage $usage): array
    {
        $body['usage'] = [
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'cache_creation_input_tokens' => $usage->cacheCreationTokens,
            'cache_read_input_tokens' => $usage->cacheReadTokens,
        ];

        // Mirror Anthropic's TTL breakdown so a client inspecting the aggregated
        // multi-turn usage (e.g. for its own cost estimate) sees the same shape
        // it would from a single-turn response. Clamp defensively: summing
        // per-turn usage (sumUsage()) should never let the 1h count exceed the
        // total, but a negative ephemeral_5m_input_tokens would be an invalid
        // token count and could confuse a client parsing this breakdown.
        if ($usage->cacheCreation1hTokens > 0) {
            $cacheCreation1h = min($usage->cacheCreation1hTokens, $usage->cacheCreationTokens);
            $body['usage']['cache_creation'] = [
                'ephemeral_5m_input_tokens' => $usage->cacheCreationTokens - $cacheCreation1h,
                'ephemeral_1h_input_tokens' => $cacheCreation1h,
            ];
        }

        return $body;
    }
}
