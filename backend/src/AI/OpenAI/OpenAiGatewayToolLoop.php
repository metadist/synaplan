<?php

declare(strict_types=1);

namespace App\AI\OpenAI;

use App\AI\Messages\Mcp\McpToolCatalogAdapter;
use App\AI\Messages\Tools\GatewayToolCatalog;
use App\AI\Messages\Tools\WebSearchTool;
use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\McpClient;
use App\Service\Mcp\McpClientException;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\RateLimitService;
use Psr\Log\LoggerInterface;

/**
 * OpenAI-shaped hybrid tool loop for POST /v1/chat/completions.
 *
 * Injects Synaplan MCP + web_search on dual-gated models, executes only what
 * Synaplan owns, and relays client-owned tool_calls as finish_reason
 * tool_calls. Intermediate server rounds are never streamed.
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
    ) {
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $options
     *
     * @return array<string, mixed> AiFacade::chat shape plus loop_notes
     */
    public function complete(User $user, array $messages, array $options): array
    {
        if ('none' === ($options['tool_choice'] ?? null)) {
            return $this->aiFacade->chat($messages, $user->getId(), $options);
        }

        $clientTools = isset($options['tools']) && is_array($options['tools']) ? $options['tools'] : [];
        $snapshot = $this->catalog->build($user, $clientTools);
        $merged = $this->mergeTools($snapshot['tools'], $clientTools);
        if ([] === $merged) {
            return $this->aiFacade->chat($messages, $user->getId(), $options);
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

            $last = $this->aiFacade->chat($messages, $user->getId(), $options);
            $summedUsage = $this->addUsage($summedUsage, is_array($last['usage'] ?? null) ? $last['usage'] : []);

            $toolCalls = $this->normalizeToolCalls($last['tool_calls'] ?? []);
            $finish = is_string($last['finish_reason'] ?? null) ? $last['finish_reason'] : '';
            if ('tool_calls' !== $finish && [] === $toolCalls) {
                $last['usage'] = $summedUsage;
                $last['loop_notes'] = $notes;

                return $last;
            }

            $partition = $this->partition($toolCalls, $snapshot['dispatch']);
            if ([] !== $partition['client']) {
                if ([] !== $partition['ours']) {
                    $executed = $this->executeOurs($partition['ours'], $snapshot['dispatch'], $user, $notes);
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

            $results = $this->executeOurs($partition['ours'], $snapshot['dispatch'], $user, $notes);
            $messages = $this->appendToolTurn($messages, $toolCalls, $results);
        }

        $last['usage'] = $summedUsage;
        $last['loop_notes'] = $notes;

        return $last;
    }

    /**
     * Stream only the final assistant text or the client-owned tool_calls.
     * Intermediate Synaplan rounds are suppressed (run via complete()).
     *
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $options
     *
     * @return array<string, mixed>
     */
    public function stream(User $user, array $messages, callable $callback, array $options): array
    {
        $result = $this->complete($user, $messages, $options);
        $toolCalls = $this->normalizeToolCalls($result['tool_calls'] ?? []);
        if ([] !== $toolCalls && 'tool_calls' === ($result['finish_reason'] ?? '')) {
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
            $callback(['type' => 'finish', 'finish_reason' => 'tool_calls']);
        } else {
            $content = is_string($result['content'] ?? null) ? $result['content'] : '';
            if ('' !== $content) {
                $callback($content);
            }
            $callback(['type' => 'finish', 'finish_reason' => is_string($result['finish_reason'] ?? null) ? $result['finish_reason'] : 'stop']);
        }

        return [
            'provider' => $result['provider'] ?? 'unknown',
            'model' => $result['model'] ?? ($options['model'] ?? 'unknown'),
            'usage' => $result['usage'] ?? [],
            'loop_notes' => $result['loop_notes'] ?? [],
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
    private function executeOurs(array $toolCalls, array $dispatch, User $user, array &$notes): array
    {
        if (count($toolCalls) > self::MAX_TOOLS_PER_TURN) {
            $toolCalls = array_slice($toolCalls, 0, self::MAX_TOOLS_PER_TURN);
        }

        $results = [];
        foreach ($toolCalls as $call) {
            $id = (string) ($call['id'] ?? '');
            $name = (string) ($call['function']['name'] ?? '');
            $arguments = $this->decodeArguments($call['function']['arguments'] ?? '{}');
            $entry = $dispatch[$name] ?? null;

            $rate = $this->rateLimitService->checkLimit($user, 'MESSAGES');
            if (!$rate['allowed']) {
                $results[] = $this->toolMessage($id, 'Rate limit exceeded; tool call skipped.');
                continue;
            }

            if (null === $entry) {
                $results[] = $this->toolMessage($id, 'Unknown Synaplan tool.');
                continue;
            }

            if (GatewayToolCatalog::KIND_NATIVE === $entry['kind']) {
                $results[] = $this->executeNative($entry['tool'], $arguments, $id, $user, $notes);
                continue;
            }

            if ($this->mcpCatalogAdapter->isMutatingTool($entry['annotations'])) {
                $results[] = $this->toolMessage($id, sprintf("the tool '%s' can modify data and is not allowed (read-only)", $entry['tool']));
                continue;
            }

            $server = $this->servers->findByIdAndUser($entry['serverId'], (int) $user->getId());
            if (null === $server || !$server->isEnabled()) {
                $results[] = $this->toolMessage($id, 'MCP server is not available.');
                continue;
            }

            try {
                $callResult = $this->mcpClient->callTool($server, $entry['tool'], $arguments);
                $text = $this->formatMcpContent($callResult['content']);
                $results[] = $this->toolMessage($id, $text);
                $notes[] = sprintf('[mcp:%d/%s]', $entry['serverId'], $entry['tool']);
            } catch (McpClientException $e) {
                $this->logger->warning('OpenAiGatewayToolLoop: MCP tool call failed', [
                    'server_id' => $entry['serverId'],
                    'tool' => $entry['tool'],
                    'error' => $e->getMessage(),
                ]);
                $results[] = $this->toolMessage($id, 'Tool call failed: '.$e->getMessage());
            }
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<string>         $notes
     *
     * @return array<string, mixed>
     */
    private function executeNative(string $tool, array $arguments, string $id, User $user, array &$notes): array
    {
        if (WebSearchTool::NAME !== $tool) {
            return $this->toolMessage($id, sprintf('Unknown Synaplan tool `%s`.', $tool));
        }

        $result = $this->webSearchTool->execute($arguments);
        $query = $result['query'];
        $notes[] = '[web_search:'.('' !== $query ? $query : '…').']';

        return $this->toolMessage($id, $this->clamp($result['text']));
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

    /**
     * @param list<array<string, mixed>> $content
     */
    private function formatMcpContent(array $content): string
    {
        $parts = [];
        foreach ($content as $block) {
            if ('text' === ($block['type'] ?? '') && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }

        $text = trim(implode("\n\n", $parts));

        return '' !== $text ? $text : '(empty tool result)';
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
