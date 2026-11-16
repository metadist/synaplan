<?php

declare(strict_types=1);

namespace App\Tests\Eval\ChatLoop;

/**
 * Reads the events of one turn as the web client gets them and measures it.
 *
 * A turn the planner split into steps streams its text as `task_chunk`
 * events into cards; the card of the plan's reply step is then the answer.
 *
 * Terminal means the server said how the turn ended: a `complete` event, an
 * error event, or the end of the research run the turn handed over to. A
 * stream that just closes is not terminal.
 */
final class TurnMeter
{
    private const FAILED_STATUSES = ['error', 'cancelled', 'guest_limit_reached'];
    private const RESEARCH_ENDED = ['complete', 'stopped', 'failed'];

    private string $state = TurnOutcome::STATE_NONE;
    private ?string $reason = null;
    private ?float $firstTokenAt = null;
    private string $text = '';
    private ?string $replyNode = null;
    /** @var array<string, string> step id => the text of its card */
    private array $cards = [];
    private float $cost = 0.0;
    private bool $priceKnown = true;
    private int $tokens = 0;
    private ?string $researchRunId = null;
    private int $statements = 0;
    private int $supported = 0;
    /** @var list<string> */
    private array $stages = [];

    public function __construct(
        private readonly CorpusTurn $turn,
        private readonly float $startedAt,
    ) {
    }

    /**
     * @param array<mixed> $event one decoded `data:` frame
     */
    public function event(array $event, float $now): void
    {
        $status = \is_string($event['status'] ?? null) ? $event['status'] : '';
        if ('data' === $status) {
            $chunk = \is_string($event['chunk'] ?? null) ? $event['chunk'] : '';
            if ('' !== $chunk) {
                $this->firstTokenAt ??= $now;
                $this->text .= $chunk;
            }

            return;
        }
        if ('complete' === $status) {
            $this->complete($event);

            return;
        }
        if (\in_array($status, ['plan', 'plan_discarded', 'task_chunk', 'task_update'], true)) {
            $this->planEvent($status, \is_array($event['metadata'] ?? null) ? $event['metadata'] : [], $now);
        }
        if (\in_array($status, self::FAILED_STATUSES, true)) {
            $this->state = TurnOutcome::STATE_FAILED;
            $this->reason = self::firstText($event, ['error', 'message']) ?? $status;

            return;
        }
        if ('' !== $status && 'perf' !== $status && !\in_array($status, $this->stages, true)) {
            $this->stages[] = $status;
        }
    }

    /**
     * The research run this turn handed over to, while it is still open.
     */
    public function openResearchRun(): ?string
    {
        return TurnOutcome::STATE_NONE === $this->state ? $this->researchRunId : null;
    }

    /**
     * @param array<mixed> $run the body of GET /api/v1/research/{id}
     *
     * @return bool true once the run has ended
     */
    public function research(array $run): bool
    {
        $status = \is_string($run['status'] ?? null) ? $run['status'] : '';
        if (!\in_array($status, self::RESEARCH_ENDED, true)) {
            return false;
        }

        $report = \is_array($run['report'] ?? null) ? $run['report'] : [];
        if (\is_string($report['markdown'] ?? null) && '' !== trim($report['markdown'])) {
            $this->text = $report['markdown'];
        }
        $cost = \is_array($run['cost'] ?? null) ? $run['cost'] : [];
        if (is_numeric($cost['spent'] ?? null)) {
            $this->cost += (float) $cost['spent'];
        }
        $check = \is_array($run['check'] ?? null) ? $run['check'] : [];
        $this->statements = \is_int($check['statements'] ?? null) ? $check['statements'] : 0;
        $this->supported = \is_int($check['supported'] ?? null) ? $check['supported'] : 0;
        $outcome = \is_array($run['outcome'] ?? null) ? $run['outcome'] : [];
        $this->state = 'complete' === $status ? TurnOutcome::STATE_ANSWERED : TurnOutcome::STATE_FAILED;
        $this->reason = 'complete' === $status ? null : (self::firstText($outcome, ['code', 'text']) ?? $status);

        return true;
    }

    public function outcome(float $endedAt): TurnOutcome
    {
        $text = $this->answer();

        return new TurnOutcome(
            $this->turn->id,
            $this->turn->group,
            $this->state,
            TurnOutcome::STATE_NONE === $this->state ? ($this->reason ?? 'The stream ended without saying how the turn ended.') : $this->reason,
            null === $this->firstTokenAt ? null : $this->firstTokenAt - $this->startedAt,
            $endedAt - $this->startedAt,
            $this->cost,
            $this->priceKnown,
            $this->tokens,
            TurnOutcome::STATE_ANSWERED === $this->state ? $this->recall($text) : null,
            $text,
            $this->stages,
            $this->researchRunId,
            $this->statements,
            $this->supported,
        );
    }

    /**
     * A request that never produced an event stream: the reason is kept and
     * the turn stays without a terminal result.
     */
    public function unreachable(string $reason): void
    {
        $this->reason = $reason;
    }

    /**
     * @param array<mixed> $event
     */
    private function complete(array $event): void
    {
        $this->addUsage($event['usage'] ?? null);
        foreach (\is_array($event['usage_extra'] ?? null) ? $event['usage_extra'] : [] as $extra) {
            $this->addUsage($extra);
        }

        $failure = self::firstText($event, ['errorReason']);
        if (null !== $failure) {
            $this->state = TurnOutcome::STATE_FAILED;
            $this->reason = $failure;

            return;
        }
        $runId = self::firstText($event, ['researchRunId']);
        if (null !== $runId) {
            $this->researchRunId = $runId;

            return;
        }
        $this->state = TurnOutcome::STATE_ANSWERED;
    }

    /**
     * @param array<mixed> $metadata
     */
    private function planEvent(string $status, array $metadata, float $now): void
    {
        if ('plan' === $status) {
            $this->replyNode = \is_string($metadata['reply_node'] ?? null) && '' !== $metadata['reply_node'] ? $metadata['reply_node'] : null;
            $this->cards = [];

            return;
        }
        if ('plan_discarded' === $status) {
            // The plan failed as a whole; the answer follows as `data`.
            $this->replyNode = null;
            $this->cards = [];

            return;
        }
        $node = $metadata['node_id'] ?? null;
        if (!\is_string($node) || '' === $node) {
            return;
        }
        $chunk = $metadata['chunk'] ?? null;
        if ('task_chunk' === $status && \is_string($chunk) && '' !== $chunk) {
            $this->firstTokenAt ??= $now;
            $this->cards[$node] = ($this->cards[$node] ?? '').$chunk;

            return;
        }
        // A settled step carries the whole body of its card.
        $settled = $metadata['text'] ?? null;
        if ('task_update' === $status && \is_string($settled) && '' !== trim($settled)) {
            $this->cards[$node] = $settled;
        }
    }

    /**
     * What the person reads as the answer: the bubble, else the card of the
     * plan's reply step, else every card.
     */
    private function answer(): string
    {
        if ('' !== trim($this->text) || [] === $this->cards) {
            return $this->text;
        }
        if (null !== $this->replyNode && '' !== trim($this->cards[$this->replyNode] ?? '')) {
            return $this->cards[$this->replyNode];
        }

        return implode("\n\n", $this->cards);
    }

    private function addUsage(mixed $usage): void
    {
        if (!\is_array($usage)) {
            return;
        }
        if (is_numeric($usage['cost'] ?? null)) {
            $this->cost += (float) $usage['cost'];
        }
        if (is_numeric($usage['totalTokens'] ?? null)) {
            $this->tokens += (int) $usage['totalTokens'];
        }
        if (false === ($usage['priceKnown'] ?? true)) {
            $this->priceKnown = false;
        }
    }

    private function recall(string $text): ?float
    {
        if ([] === $this->turn->expect) {
            return null;
        }
        $met = 0;
        foreach ($this->turn->expect as $assertion) {
            foreach ($assertion as $needle) {
                if (false === mb_stripos($text, $needle)) {
                    continue 2;
                }
            }
            ++$met;
        }

        return $met / \count($this->turn->expect);
    }

    /**
     * @param array<mixed> $row
     * @param list<string> $keys
     */
    private static function firstText(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (\is_string($row[$key] ?? null) && '' !== trim($row[$key])) {
                return $row[$key];
            }
        }

        return null;
    }
}
