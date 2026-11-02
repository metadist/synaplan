<?php

declare(strict_types=1);

namespace App\AI\OpenAI;

use App\AI\Messages\Mcp\McpToolCatalogAdapter;
use App\AI\Messages\Tools\CodeExecutionTool;
use App\AI\Messages\Tools\GatewayToolCatalog;
use App\AI\Messages\Tools\WebSearchTool;
use App\AI\Service\AiFacade;
use App\AI\Stream\StreamChunk;
use App\Entity\ComputeRun;
use App\Entity\User;
use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\McpClient;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\RateLimitService;
use App\Service\Runtime\RuntimeProfile;
use App\Service\Tool\Exception\ToolNotRegisteredException;
use App\Service\Tool\Execution\BuiltinToolExecutor;
use App\Service\Tool\Execution\GateMode;
use App\Service\Tool\Execution\GateRequest;
use App\Service\Tool\Execution\McpToolExecutor;
use App\Service\Tool\Execution\ToolCaller;
use App\Service\Tool\Execution\ToolChannel;
use App\Service\Tool\Execution\ToolInvocation;
use App\Service\Tool\Execution\ToolInvoker;
use App\Service\Tool\Execution\ToolInvokerSlot;
use App\Service\Tool\Execution\ToolOutcomeKind;
use App\Service\Tool\Policy\PolicyContext;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolExecutionGate;
use App\Service\Tool\ToolRegistry;
use App\Service\Tool\ToolsConfig;
use App\Service\Tool\ToolSource;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * OpenAI-shaped hybrid tool loop for POST /v1/chat/completions.
 *
 * Injects Synaplan MCP + web_search on dual-gated models, executes only what
 * Synaplan owns, and relays client-owned tool_calls as finish_reason
 * tool_calls. Synaplan-owned tool calls are not streamed; answer text is
 * forwarded as the model produces it.
 *
 * Mixed turns (owned + client-owned in one answer): execute owned calls
 * first, then return the leftover client-owned tool_calls without a
 * re-prompt. Locked by OpenAiGatewayToolLoopTest.
 *
 * Does not change /v1/messages defaults. tool_choice: none skips inject
 * and the loop.
 *
 * @phpstan-type DispatchEntry array{kind: string, serverId: int, tool: string, annotations: array<string, mixed>}
 */
final readonly class OpenAiGatewayToolLoop
{
    private const MAX_TOOL_RESULT_CHARS = 12000;
    private const MAX_TOOLS_PER_TURN = 16;
    private const WALL_CLOCK_SECONDS = 240;

    /**
     * Chat option the controller sets when the Synaplan Demo gate already
     * waived the shared hourly MESSAGES cap. Removed before a provider sees it.
     */
    public const DEMO_HOURLY_CAP_WAIVED = 'demo_hourly_cap_waived';

    private ToolInvokerSlot $toolInvoker;

    public function __construct(
        private OpenAiGatewayToolCatalog $catalog,
        private AiFacade $aiFacade,
        private WebSearchTool $webSearchTool,
        private McpClient $mcpClient,
        private McpToolCatalogAdapter $mcpCatalogAdapter,
        private McpServerConfigRepository $servers,
        private MessagesGatewayConfig $config,
        private RateLimitService $rateLimitService,
        private LoggerInterface $logger,
        private ?ToolExecutionGate $executionGate = null,
        private ?ToolRegistry $toolRegistry = null,
        private ?ToolsConfig $toolsConfig = null,
        private ?CodeExecutionTool $codeExecutionTool = null,
    ) {
        $this->toolInvoker = new ToolInvokerSlot();
    }

    #[Required]
    public function setToolInvoker(ToolInvoker $toolInvoker): void
    {
        $this->toolInvoker->set($toolInvoker);
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $options
     *
     * @return array<string, mixed> AiFacade::chat shape plus loop_notes
     */
    public function complete(User $user, array $messages, array $options): array
    {
        $demoHourlyCapWaived = $this->pullDemoHourlyCapWaiver($options);
        if ('none' === ($options['tool_choice'] ?? null)) {
            return $this->aiFacade->chat($messages, $user->getId(), $options);
        }

        $clientTools = isset($options['tools']) && is_array($options['tools']) ? $options['tools'] : [];
        $assistant = $options['runtime_profile'] ?? null;
        $assistant = $assistant instanceof RuntimeProfile ? $assistant : null;
        $snapshot = $this->catalog->build($user, $clientTools, $assistant);
        $merged = $this->mergeTools($snapshot['tools'], $clientTools);
        if ([] === $merged) {
            return $this->aiFacade->chat($messages, $user->getId(), $options);
        }

        $options['tools'] = $merged;
        $options['usage_turn_id'] = $options['usage_turn_id'] ?? ('openai-loop-'.bin2hex(random_bytes(8)));
        $maxIterations = $this->config->mcpMaxIterations($user->getId());
        $deadline = microtime(true) + self::WALL_CLOCK_SECONDS;
        $notes = [];
        $summedUsage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
        $summedCharged = 0.0;
        $metered = false;
        $priceKnown = true;
        $last = [];

        for ($i = 0; $i < $maxIterations; ++$i) {
            if (microtime(true) > $deadline) {
                break;
            }

            $last = $this->aiFacade->chat($messages, $user->getId(), $options);
            $summedUsage = $this->addUsage($summedUsage, is_array($last['usage'] ?? null) ? $last['usage'] : []);
            [$metered, $summedCharged, $priceKnown] = self::foldCharged($last, $metered, $summedCharged, $priceKnown);

            $toolCalls = $this->normalizeToolCalls($last['tool_calls'] ?? []);
            $finish = is_string($last['finish_reason'] ?? null) ? $last['finish_reason'] : '';
            if ('tool_calls' !== $finish && [] === $toolCalls) {
                return self::finalizeComplete($last, $summedUsage, $notes, $metered, $summedCharged, $priceKnown);
            }

            $partition = $this->partition($toolCalls, $snapshot['dispatch']);
            if ([] !== $partition['client']) {
                if ([] !== $partition['ours']) {
                    $executed = $this->executeOurs(
                        $partition['ours'],
                        $snapshot['dispatch'],
                        $user,
                        $notes,
                        $assistant,
                        $demoHourlyCapWaived,
                    );
                    unset($executed);
                }
                $last['tool_calls'] = $partition['client'];
                $last['content'] = '';
                $last['finish_reason'] = 'tool_calls';
                $last['usage'] = $summedUsage;
                $last['loop_notes'] = $notes;

                return $last;
            }

            if ([] === $partition['ours']) {
                $last['usage'] = $summedUsage;
                $last['loop_notes'] = $notes;

                return $last;
            }

            $results = $this->executeOurs(
                $partition['ours'],
                $snapshot['dispatch'],
                $user,
                $notes,
                $assistant,
                $demoHourlyCapWaived,
            );
            $messages = $this->appendToolTurn($messages, $toolCalls, $results);
        }

        return self::finalizeComplete($last, $summedUsage, $notes, $metered, $summedCharged, $priceKnown);
    }

    /**
     * @param array<string, mixed> $round
     *
     * @return array{0: bool, 1: float, 2: bool}
     */
    private static function foldCharged(array $round, bool $metered, float $charged, bool $priceKnown): array
    {
        if (true === ($round['facade_metered'] ?? false)) {
            $metered = true;
            if (isset($round['facade_charged_cost']) && is_numeric($round['facade_charged_cost'])) {
                $charged += (float) $round['facade_charged_cost'];
            }
            if (false === ($round['facade_price_known'] ?? true)) {
                $priceKnown = false;
            }
        }

        return [$metered, $charged, $priceKnown];
    }

    /**
     * @param array<string, mixed> $last
     * @param array<string, int>   $summedUsage
     * @param list<string>         $notes
     *
     * @return array<string, mixed>
     */
    private static function finalizeComplete(array $last, array $summedUsage, array $notes, bool $metered, float $charged, bool $priceKnown): array
    {
        $last['usage'] = $summedUsage;
        $last['loop_notes'] = $notes;
        if ($metered) {
            $last['facade_metered'] = true;
            $last['facade_charged_cost'] = number_format($charged, 6, '.', '');
            $last['facade_price_known'] = $priceKnown;
        }

        return $last;
    }

    /**
     * Stream answer text as the model produces it. Synaplan-owned tool rounds
     * stay inside the loop; only client-owned tool_calls are emitted as deltas.
     *
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $options
     *
     * @return array<string, mixed>
     */
    public function stream(User $user, array $messages, callable $callback, array $options): array
    {
        $demoHourlyCapWaived = $this->pullDemoHourlyCapWaiver($options);
        $forwardText = static function (mixed $chunk) use ($callback): void {
            if (!\is_string($chunk) && !\is_array($chunk)) {
                return;
            }
            $text = StreamChunk::visibleText($chunk);
            if ('' !== $text) {
                $callback($text);
            }
        };

        if ('none' === ($options['tool_choice'] ?? null)) {
            $result = $this->aiFacade->chatStream($messages, $forwardText, $user->getId(), $options);
            $callback(['type' => 'finish', 'finish_reason' => 'stop']);

            return $this->streamMetadata($result, $options, []);
        }

        $clientTools = isset($options['tools']) && is_array($options['tools']) ? $options['tools'] : [];
        $assistant = $options['runtime_profile'] ?? null;
        $assistant = $assistant instanceof RuntimeProfile ? $assistant : null;
        $snapshot = $this->catalog->build($user, $clientTools, $assistant);
        $merged = $this->mergeTools($snapshot['tools'], $clientTools);
        if ([] === $merged) {
            $result = $this->aiFacade->chatStream($messages, $forwardText, $user->getId(), $options);
            $callback(['type' => 'finish', 'finish_reason' => 'stop']);

            return $this->streamMetadata($result, $options, []);
        }

        $options['tools'] = $merged;
        $maxIterations = $this->config->mcpMaxIterations($user->getId());
        $deadline = microtime(true) + self::WALL_CLOCK_SECONDS;
        $notes = [];
        $summedUsage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
        $last = [];

        for ($i = 0; $i < $maxIterations; ++$i) {
            if (microtime(true) > $deadline) {
                break;
            }

            $last = $this->aiFacade->chatStream($messages, $forwardText, $user->getId(), $options);
            $summedUsage = $this->addUsage($summedUsage, is_array($last['usage'] ?? null) ? $last['usage'] : []);

            $toolCalls = $this->normalizeToolCalls($last['tool_calls'] ?? []);
            $finish = is_string($last['finish_reason'] ?? null) ? $last['finish_reason'] : '';
            if ('tool_calls' !== $finish && [] === $toolCalls) {
                $callback(['type' => 'finish', 'finish_reason' => '' !== $finish ? $finish : 'stop']);

                return $this->streamMetadata($last, $options, $notes, $summedUsage);
            }

            $partition = $this->partition($toolCalls, $snapshot['dispatch']);
            if ([] !== $partition['client']) {
                if ([] !== $partition['ours']) {
                    $executed = $this->executeOurs(
                        $partition['ours'],
                        $snapshot['dispatch'],
                        $user,
                        $notes,
                        $assistant,
                        $demoHourlyCapWaived,
                    );
                    unset($executed);
                }
                $this->emitClientToolDeltas($partition['client'], $callback);
                $callback(['type' => 'finish', 'finish_reason' => 'tool_calls']);

                return $this->streamMetadata($last, $options, $notes, $summedUsage);
            }

            if ([] === $partition['ours']) {
                $callback(['type' => 'finish', 'finish_reason' => '' !== $finish ? $finish : 'stop']);

                return $this->streamMetadata($last, $options, $notes, $summedUsage);
            }

            $results = $this->executeOurs(
                $partition['ours'],
                $snapshot['dispatch'],
                $user,
                $notes,
                $assistant,
                $demoHourlyCapWaived,
            );
            $messages = $this->appendToolTurn($messages, $toolCalls, $results);
        }

        $callback(['type' => 'finish', 'finish_reason' => 'stop']);

        return $this->streamMetadata($last, $options, $notes, $summedUsage);
    }

    /**
     * @param list<array<string, mixed>> $toolCalls
     */
    private function emitClientToolDeltas(array $toolCalls, callable $callback): void
    {
        foreach ($toolCalls as $index => $call) {
            $fn = is_array($call['function'] ?? null) ? $call['function'] : [];
            $arguments = (string) ($fn['arguments'] ?? '{}');
            $mid = (int) max(1, (int) ceil(strlen($arguments) / 2));
            $callback([
                'type' => 'tool_call_delta',
                'index' => $index,
                'id' => $call['id'] ?? null,
                'name' => $fn['name'] ?? null,
                'arguments' => substr($arguments, 0, $mid),
            ]);
            $callback([
                'type' => 'tool_call_delta',
                'index' => $index,
                'id' => null,
                'name' => null,
                'arguments' => substr($arguments, $mid),
            ]);
        }
    }

    /**
     * @param array<string, mixed>                                                      $result
     * @param array<string, mixed>                                                      $options
     * @param list<string>                                                              $notes
     * @param array{prompt_tokens: int, completion_tokens: int, total_tokens: int}|null $usage
     *
     * @return array<string, mixed>
     */
    private function streamMetadata(array $result, array $options, array $notes, ?array $usage = null): array
    {
        return [
            'provider' => $result['provider'] ?? 'unknown',
            'model' => $result['model'] ?? ($options['model'] ?? 'unknown'),
            'usage' => $usage ?? (is_array($result['usage'] ?? null) ? $result['usage'] : []),
            'loop_notes' => $notes,
            'facade_metered' => $result['facade_metered'] ?? false,
            'facade_charged_cost' => $result['facade_charged_cost'] ?? null, 'facade_price_known' => $result['facade_price_known'] ?? true,
        ];
    }

    /**
     * @param list<array<string, mixed>> $synaplanTools
     * @param list<array<string, mixed>> $clientTools
     *
     * @return list<array<string, mixed>>
     */
    private function mergeTools(array $synaplanTools, array $clientTools): array
    {
        return array_merge($synaplanTools, $clientTools);
    }

    /**
     * @param list<array<string, mixed>>   $toolCalls
     * @param array<string, DispatchEntry> $dispatch
     *
     * @return array{ours: list<array<string, mixed>>, client: list<array<string, mixed>>}
     */
    private function partition(array $toolCalls, array $dispatch): array
    {
        $ours = [];
        $client = [];
        foreach ($toolCalls as $call) {
            $name = (string) ($call['function']['name'] ?? '');
            if (isset($dispatch[$name]) || $this->mcpCatalogAdapter->isOurs($name)) {
                $ours[] = $call;
            } else {
                $client[] = $call;
            }
        }

        return ['ours' => $ours, 'client' => $client];
    }

    /**
     * @param list<array<string, mixed>>   $toolCalls
     * @param array<string, DispatchEntry> $dispatch
     * @param list<string>                 $notes
     *
     * @return list<array<string, mixed>>
     */
    private function executeOurs(
        array $toolCalls,
        array $dispatch,
        User $user,
        array &$notes,
        ?RuntimeProfile $assistant = null,
        bool $demoHourlyCapWaived = false,
    ): array {
        if (count($toolCalls) > self::MAX_TOOLS_PER_TURN) {
            $toolCalls = array_slice($toolCalls, 0, self::MAX_TOOLS_PER_TURN);
        }

        $results = [];
        foreach ($toolCalls as $call) {
            $id = (string) ($call['id'] ?? '');
            $name = (string) ($call['function']['name'] ?? '');
            $arguments = $this->decodeArguments($call['function']['arguments'] ?? '{}');
            $entry = $dispatch[$name] ?? null;

            if (!$demoHourlyCapWaived) {
                $rate = $this->rateLimitService->checkLimit($user, 'MESSAGES');
                if (!$rate['allowed']) {
                    $results[] = $this->toolMessage($id, 'Rate limit exceeded; tool call skipped.');
                    continue;
                }
            }

            if (null === $entry) {
                $results[] = $this->toolMessage($id, 'Unknown Synaplan tool.');
                continue;
            }

            if (GatewayToolCatalog::KIND_NATIVE === $entry['kind']) {
                $results[] = $this->executeNative($entry, $name, $arguments, $id, $user, $notes, $assistant);
                continue;
            }

            if (GatewayToolCatalog::KIND_CUSTOM === $entry['kind']) {
                $results[] = $this->toolMessage($id, 'This custom tool cannot run on this channel.');
                continue;
            }

            if ($this->mcpCatalogAdapter->isMutatingTool($entry['annotations']) && !$this->approvalsOn((int) $user->getId())) {
                $results[] = $this->toolMessage($id, sprintf("the tool '%s' can modify data and is not allowed (read-only)", $entry['tool']));
                continue;
            }

            $server = $this->servers->findByIdAndUser($entry['serverId'], (int) $user->getId());
            if (null === $server || !$server->isEnabled()) {
                $results[] = $this->toolMessage($id, 'MCP server is not available.');
                continue;
            }

            $results[] = $this->executeMcp($entry, $name, $arguments, $id, $user, $notes);
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function pullDemoHourlyCapWaiver(array &$options): bool
    {
        $waived = true === ($options[self::DEMO_HOURLY_CAP_WAIVED] ?? false);
        unset($options[self::DEMO_HOURLY_CAP_WAIVED]);

        return $waived;
    }

    /**
     * @param DispatchEntry        $entry
     * @param array<string, mixed> $arguments
     * @param list<string>         $notes
     *
     * @return array<string, mixed>
     */
    private function executeMcp(array $entry, string $name, array $arguments, string $id, User $user, array &$notes): array
    {
        $this->assertRegistered($user, $name);
        $outcome = $this->tools()->invoke(
            $this->descriptorFor((int) $user->getId(), $name, $entry),
            $this->invocation($user, $name, $arguments),
        );
        $blocked = $this->blocked($id, $outcome);
        if (null !== $blocked) {
            return $blocked;
        }
        if (true === ($outcome->details['transport'] ?? false)) {
            $this->logger->warning('OpenAiGatewayToolLoop: MCP tool call failed', [
                'server_id' => $entry['serverId'],
                'tool' => $entry['tool'],
                'error' => $outcome->resultText,
            ]);

            return $this->toolMessage($id, 'Tool call failed: '.$outcome->resultText);
        }
        $notes[] = sprintf('[mcp:%d/%s]', $entry['serverId'], $entry['tool']);

        return $this->toolMessage($id, $outcome->resultText);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function blocked(string $id, \App\Service\Tool\Execution\ToolOutcome $outcome): ?array
    {
        if (ToolOutcomeKind::NeedsApproval === $outcome->kind) {
            return $this->toolMessage($id, sprintf('requires approval; request #%d created', (int) $outcome->approvalId));
        }
        if (ToolOutcomeKind::Refused === $outcome->kind) {
            return $this->toolMessage($id, $outcome->resultText);
        }

        return null;
    }

    private function approvalsOn(int $userId): bool
    {
        return null !== $this->toolsConfig && $this->toolsConfig->isApprovalsEnabled($userId);
    }

    /**
     * @param DispatchEntry        $entry
     * @param array<string, mixed> $arguments
     * @param list<string>         $notes
     *
     * @return array<string, mixed>
     */
    private function executeNative(array $entry, string $name, array $arguments, string $id, User $user, array &$notes, ?RuntimeProfile $assistant = null): array
    {
        $this->assertRegistered($user, $name);
        $tool = $entry['tool'];
        $decideOnly = CodeExecutionTool::NAME === $tool;
        $outcome = $this->tools()->invoke(
            $this->descriptorFor((int) $user->getId(), $name, $entry),
            $this->invocation($user, $name, $arguments, $assistant, $decideOnly),
        );
        $blocked = $this->blocked($id, $outcome);
        if (null !== $blocked) {
            return $blocked;
        }
        if ($decideOnly) {
            if (null === $this->codeExecutionTool) {
                return $this->toolMessage($id, sprintf('Unknown Synaplan tool `%s`.', $tool));
            }
            $result = $this->codeExecutionTool->execute(
                $arguments,
                $user,
                ComputeRun::VIA_GATEWAY_OPENAI,
                $assistant,
                null,
                $assistant?->promptId,
            );
            $notes[] = '[code_execution]';

            return $this->toolMessage($id, $result['text']);
        }
        if (WebSearchTool::NAME === $tool) {
            $query = $outcome->details['usageInput'] ?? '';
            $notes[] = '[web_search:'.(is_string($query) && '' !== $query ? $query : '…').']';
        }

        return $this->toolMessage($id, $outcome->resultText);
    }

    private function assertRegistered(User $user, string $name): void
    {
        $userId = (int) $user->getId();
        if ($this->registryEnabled($userId) && null === $this->toolRegistry?->get($userId, $name)) {
            throw new ToolNotRegisteredException($name);
        }
    }

    /**
     * A disabled registry must not call ToolRegistry::get(): that lists
     * every source, and a cold MCP catalog waits on each server's timeout.
     */
    private function registryEnabled(int $userId): bool
    {
        return null !== $this->toolRegistry
            && null !== $this->toolsConfig
            && $this->toolsConfig->isRegistryEnabled($userId);
    }

    /**
     * @param DispatchEntry $entry
     */
    private function descriptorFor(int $userId, string $name, array $entry): ToolDescriptor
    {
        if ($this->registryEnabled($userId)) {
            $existing = $this->toolRegistry?->get($userId, $name);
            if (null !== $existing) {
                return $existing;
            }
        }
        $source = match ($entry['kind']) {
            GatewayToolCatalog::KIND_CUSTOM => ToolSource::Custom,
            GatewayToolCatalog::KIND_NATIVE => ToolSource::Builtin,
            default => ToolSource::Mcp,
        };

        return new ToolDescriptor($name, $entry['tool'], '', [], SideEffect::Read, $source, $userId, null, [
            'serverId' => $entry['serverId'],
            'tool' => $entry['tool'],
        ]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function invocation(User $user, string $name, array $arguments, ?RuntimeProfile $assistant = null, bool $decideOnly = false): ToolInvocation
    {
        return new ToolInvocation(
            $name,
            $arguments,
            new ToolCaller((int) $user->getId(), ToolChannel::OPENAI),
            GateMode::AsTodayWhenApprovalsOn,
            decision: new GateRequest($user, PolicyContext::Interactive, 'chat:0'),
            decideOnly: $decideOnly,
            user: $user,
            assistant: $assistant,
        );
    }

    private function tools(): ToolInvoker
    {
        return $this->toolInvoker->get() ?? new ToolInvoker(
            [
                new McpToolExecutor($this->mcpClient, $this->servers),
                new BuiltinToolExecutor(webSearch: $this->webSearchTool),
            ],
            gate: $this->executionGate,
            toolsConfig: $this->toolsConfig,
        );
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $toolCalls
     * @param list<array<string, mixed>> $results
     *
     * @return list<array<string, mixed>>
     */
    private function appendToolTurn(array $messages, array $toolCalls, array $results): array
    {
        $messages[] = [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => $toolCalls,
        ];
        foreach ($results as $result) {
            $messages[] = $result;
        }

        return $messages;
    }

    /**
     * @return array<string, mixed>
     */
    private function toolMessage(string $toolCallId, string $content): array
    {
        return [
            'role' => 'tool',
            'tool_call_id' => $toolCallId,
            'content' => $this->clamp($content),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeArguments(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || '' === $raw) {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function clamp(string $text): string
    {
        if (mb_strlen($text) > self::MAX_TOOL_RESULT_CHARS) {
            return mb_substr($text, 0, self::MAX_TOOL_RESULT_CHARS).'…';
        }

        return '' !== $text ? $text : '(empty tool result)';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeToolCalls(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $call) {
            if (is_array($call)) {
                $out[] = $call;
            }
        }

        return $out;
    }

    /**
     * @param array{prompt_tokens: int, completion_tokens: int, total_tokens: int} $sum
     * @param array<string, mixed>                                                 $usage
     *
     * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int}
     */
    private function addUsage(array $sum, array $usage): array
    {
        $sum['prompt_tokens'] += (int) ($usage['prompt_tokens'] ?? 0);
        $sum['completion_tokens'] += (int) ($usage['completion_tokens'] ?? 0);
        $sum['total_tokens'] += (int) ($usage['total_tokens'] ?? 0);

        return $sum;
    }
}
