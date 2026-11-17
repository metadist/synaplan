<?php

declare(strict_types=1);

namespace App\Tests\Eval\ChatLoop;

/**
 * What one turn cost and delivered. A turn without a terminal result
 * (`STATE_NONE`) fails the whole run: a number from it would be a guess.
 */
final readonly class TurnOutcome
{
    public const STATE_ANSWERED = 'answered';
    public const STATE_FAILED = 'failed';
    public const STATE_NONE = 'none';

    /**
     * @param list<string> $stages     the status names the stream sent, in order, without repeats
     * @param int          $statements statements the research run's own fact-check looked at; 0 for a turn without one
     * @param int          $supported  how many of them a cited source backs
     */
    public function __construct(
        public string $id,
        public string $group,
        public string $state,
        public ?string $reason,
        public ?float $firstTokenSeconds,
        public float $totalSeconds,
        public float $cost,
        public bool $priceKnown,
        public int $tokens,
        public ?float $recall,
        public string $text,
        public array $stages,
        public ?string $researchRunId,
        public int $statements = 0,
        public int $supported = 0,
    ) {
    }

    public function hasTerminalResult(): bool
    {
        return self::STATE_NONE !== $this->state;
    }
}
