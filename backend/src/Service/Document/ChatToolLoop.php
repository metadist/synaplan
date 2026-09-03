<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\AI\Service\AiFacade;
use App\Service\Document\Tool\DocumentSession;
use App\Service\Document\Tool\DocumentToolRegistry;
use App\Service\Document\Tool\DocumentToolResult;
use Psr\Log\LoggerInterface;

/**
 * Multi-round document tool loop on ChatProviderInterface / AiFacade.
 *
 * Bounds and error-as-tool_result match GatewayToolLoop. Tools never abort
 * the loop. Intermediate provider rounds are not streamed; callers get
 * {@see ChatToolLoopResult} plus optional per-step callbacks.
 */
final readonly class ChatToolLoop
{
    private const WALL_CLOCK_SECONDS = 240;
    private const MAX_TOOLS_PER_TURN = 16;
    private const MAX_RESULT_CHARS = 12000;

    public function __construct(
        private AiFacade $aiFacade,
        private DocumentToolRegistry $registry,
        private DocumentToolsConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<array<string, mixed>>                   $messages
     * @param array<string, mixed>                         $options
     * @param callable(DocumentToolResult, int): void|null $onStep
     */
    public function run(
        array $messages,
        DocumentSession $session,
        array $options,
        ?int $userId = null,
        ?callable $onStep = null,
    ): ChatToolLoopResult {
        $started = time();
        $maxIter = $this->config->maxIterations();
        $maxOps = $this->config->maxOpsPerTurn();
        $tools = $this->registry->declarationsFor($session->kind());
        $options['tools'] = $tools;
        $options['tool_choice'] = $options['tool_choice'] ?? 'auto';

        $content = '';
        $usage = [];
        $ops = 0;

        for ($i = 0; $i < $maxIter; ++$i) {
            if ((time() - $started) >= self::WALL_CLOCK_SECONDS) {
                break;
            }
            $response = $this->aiFacade->chat($messages, $userId, $options);
            $usage = $this->mergeUsage($usage, is_array($response['usage'] ?? null) ? $response['usage'] : []);
            $content = (string) ($response['content'] ?? '');
            $calls = $response['tool_calls'] ?? [];
            if (!is_array($calls) || [] === $calls) {
                break;
            }
            $calls = array_slice($calls, 0, self::MAX_TOOLS_PER_TURN);
            $messages[] = [
                'role' => 'assistant',
                'content' => $content,
                'tool_calls' => $calls,
            ];
            foreach ($calls as $call) {
                if ($ops >= $maxOps) {
                    break 2;
                }
                $name = (string) ($call['function']['name'] ?? $call['name'] ?? '');
                $id = (string) ($call['id'] ?? $name);
                $rawArgs = $call['function']['arguments'] ?? $call['arguments'] ?? '{}';
                $args = is_array($rawArgs) ? $rawArgs : (json_decode((string) $rawArgs, true) ?: []);
                if (!is_array($args)) {
                    $args = [];
                }
                $tool = $this->registry->get($name);
                if (null === $tool || !in_array($session->kind(), $tool->appliesTo(), true)) {
                    $result = DocumentToolResult::error('Unknown tool '.$name, 'processing.documentStepUnknownTool', ['name' => $name]);
                } else {
                    try {
                        $result = $tool->execute($session, $args);
                    } catch (\Throwable $e) {
                        $this->logger->warning('ChatToolLoop: tool threw', ['tool' => $name, 'error' => $e->getMessage()]);
                        $result = DocumentToolResult::error($e->getMessage(), 'processing.documentStepFailed', ['name' => $name]);
                    }
                }
                $session->record($result);
                ++$ops;
                if (null !== $onStep) {
                    $onStep($result, $ops);
                }
                $payload = $result->message;
                if (strlen($payload) > self::MAX_RESULT_CHARS) {
                    $payload = substr($payload, 0, self::MAX_RESULT_CHARS).'…';
                }
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $id,
                    'name' => $name,
                    'content' => $payload,
                ];
            }
        }

        return new ChatToolLoopResult($content, $session, $usage);
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     *
     * @return array<string, mixed>
     */
    private function mergeUsage(array $a, array $b): array
    {
        foreach ($b as $key => $value) {
            if (is_numeric($value)) {
                $a[$key] = (int) ($a[$key] ?? 0) + (int) $value;
            } else {
                $a[$key] = $value;
            }
        }

        return $a;
    }
}
