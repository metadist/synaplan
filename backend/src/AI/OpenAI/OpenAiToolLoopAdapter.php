<?php

declare(strict_types=1);

namespace App\AI\OpenAI;

use App\Service\Tool\Loop\ToolCallPartition;
use App\Service\Tool\Loop\ToolLoopAdapter;
use App\Service\Tool\Loop\ToolLoopStop;
use App\Service\Tool\Loop\ToolLoopTurn;

/**
 * Wire side of one /v1/chat/completions run: OpenAI tool_calls, Synaplan's
 * calls run first and a mixed turn hands the client's calls back without a
 * re-prompt. One instance per run; complete and stream differ only in the
 * model call.
 */
final class OpenAiToolLoopAdapter implements ToolLoopAdapter
{
    /** @var array<string, mixed> */
    private array $last = [];
    /** @var array{prompt_tokens: int, completion_tokens: int, total_tokens: int} */
    private array $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
    private string $finish = '';
    /** @var list<array<string, mixed>> */
    private array $toolCalls = [];
    /** @var list<array<string, mixed>> */
    private array $client = [];
    private bool $metered = false;
    private float $charged = 0.0;
    private bool $priceKnown = true;

    /**
     * @param list<array<string, mixed>>                                       $messages
     * @param \Closure(list<array<string, mixed>>): array<string, mixed>       $callModel
     * @param \Closure(string): bool                                           $isOurs
     * @param \Closure(list<array<string, mixed>>): list<array<string, mixed>> $runOurs
     */
    public function __construct(
        private array $messages,
        private readonly \Closure $callModel,
        private readonly \Closure $isOurs,
        private readonly \Closure $runOurs,
    ) {
    }

    public function turn(int $iteration): ToolLoopTurn
    {
        $this->last = ($this->callModel)($this->messages);
        $this->addUsage(is_array($this->last['usage'] ?? null) ? $this->last['usage'] : []);
        $this->foldCharged($this->last);

        $this->toolCalls = $this->normalizeToolCalls($this->last['tool_calls'] ?? []);
        $this->finish = is_string($this->last['finish_reason'] ?? null) ? $this->last['finish_reason'] : '';
        if ('tool_calls' !== $this->finish && [] === $this->toolCalls) {
            return ToolLoopTurn::stop(ToolLoopStop::Answered);
        }

        $partition = ToolCallPartition::of(
            $this->toolCalls,
            fn (array $call): bool => ($this->isOurs)((string) ($call['function']['name'] ?? '')),
        );
        $this->client = $partition->client;
        if ([] === $partition->ours) {
            return ToolLoopTurn::stop([] !== $this->client ? ToolLoopStop::ClientTools : ToolLoopStop::Halted);
        }

        return ToolLoopTurn::run($partition->ours);
    }

    public function execute(array $ours): ?ToolLoopStop
    {
        $results = ($this->runOurs)($ours);
        if ([] !== $this->client) {
            return ToolLoopStop::ClientTools;
        }

        $this->messages[] = [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => $this->toolCalls,
        ];
        foreach ($results as $result) {
            $this->messages[] = $result;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function last(): array
    {
        return $this->last;
    }

    /**
     * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int}
     */
    public function usage(): array
    {
        return $this->usage;
    }

    public function finishReason(): string
    {
        return $this->finish;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function clientCalls(): array
    {
        return $this->client;
    }

    /**
     * The model's last answer with the summed usage, the loop notes and the
     * summed facade charge.
     *
     * @param list<string> $notes
     *
     * @return array<string, mixed>
     */
    public function completed(array $notes): array
    {
        $last = $this->last;
        $last['usage'] = $this->usage;
        $last['loop_notes'] = $notes;
        if ($this->metered) {
            $last['facade_metered'] = true;
            $last['facade_charged_cost'] = number_format($this->charged, 6, '.', '');
            $last['facade_price_known'] = $this->priceKnown;
        }

        return $last;
    }

    /**
     * @param array<string, mixed> $round
     */
    private function foldCharged(array $round): void
    {
        if (true !== ($round['facade_metered'] ?? false)) {
            return;
        }
        $this->metered = true;
        if (isset($round['facade_charged_cost']) && is_numeric($round['facade_charged_cost'])) {
            $this->charged += (float) $round['facade_charged_cost'];
        }
        if (false === ($round['facade_price_known'] ?? true)) {
            $this->priceKnown = false;
        }
    }

    /**
     * @param array<string, mixed> $usage
     */
    private function addUsage(array $usage): void
    {
        $this->usage['prompt_tokens'] += (int) ($usage['prompt_tokens'] ?? 0);
        $this->usage['completion_tokens'] += (int) ($usage['completion_tokens'] ?? 0);
        $this->usage['total_tokens'] += (int) ($usage['total_tokens'] ?? 0);
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
}
