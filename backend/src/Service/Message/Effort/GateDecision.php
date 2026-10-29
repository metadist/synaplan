<?php

declare(strict_types=1);

namespace App\Service\Message\Effort;

/**
 * What the gate decided for one turn: a research started, the turn goes on
 * with a limit sentence first, or nothing happened (an ordinary turn).
 */
final readonly class GateDecision
{
    public const NO_SOURCES = 'no_sources';
    public const TOO_MANY = 'too_many';
    public const BUDGET = 'budget';
    public const PLAN_LIMIT = 'plan_limit';
    public const UNAVAILABLE = 'unavailable';

    /** @var list<string> */
    public const REASONS = [self::NO_SOURCES, self::TOO_MANY, self::BUDGET, self::PLAN_LIMIT, self::UNAVAILABLE];

    private function __construct(
        public ?string $runId,
        public int $minutes,
        public ?string $why,
        public ?string $limit,
    ) {
    }

    public static function none(): self
    {
        return new self(null, 0, null, null);
    }

    public static function started(string $runId, int $minutes, string $why): self
    {
        return new self($runId, $minutes, $why, null);
    }

    public static function heldBack(string $reason): self
    {
        if (!in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown research limit "%s".', $reason));
        }

        return new self(null, 0, null, $reason);
    }

    public function startedRun(): bool
    {
        return null !== $this->runId;
    }

    public function heldBackBy(): ?string
    {
        return $this->limit;
    }
}
