<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Run;

use App\Service\Run\RunState;
use App\Service\Run\RunStateCodec;

/**
 * A run of a test kind: a status, a list of done steps, and the paid calls
 * it made. Its stored form is JSON.
 */
final class TestRunState implements RunState, RunStateCodec
{
    /** A stored value the codec does not read as a run. */
    public const GONE = 'gone';

    /** The expiry the store last wrote with this state; null when it has none. */
    public ?int $expiresAt = null;

    /**
     * @param list<string> $done
     */
    public function __construct(
        public string $id = '',
        public string $status = 'running',
        public array $done = [],
        public int $paidCalls = 0,
        public int $expected = 1,
        public int $checkpoint = 0,
        public int $updated = 0,
    ) {
    }

    public function runId(): string
    {
        return $this->id;
    }

    public function expectedSeq(): int
    {
        return $this->expected;
    }

    public function checkpointSeq(): int
    {
        return $this->checkpoint;
    }

    public function isTerminal(): bool
    {
        return \in_array($this->status, ['completed', 'stopped', 'interrupted', 'failed'], true);
    }

    public function updatedAt(): int
    {
        return $this->updated;
    }

    public function stored(int $expectedSeq, int $checkpointSeq, int $now, ?int $expiresAt): string
    {
        return json_encode([
            'id' => $this->id,
            'status' => $this->status,
            'done' => $this->done,
            'paidCalls' => $this->paidCalls,
            'expected' => $expectedSeq,
            'checkpoint' => $checkpointSeq,
            'updated' => $now,
        ], \JSON_THROW_ON_ERROR);
    }

    public function checkpointed(int $expectedSeq, int $checkpointSeq, int $now, ?int $expiresAt): void
    {
        $this->expected = $expectedSeq;
        $this->checkpoint = $checkpointSeq;
        $this->updated = $now;
        $this->expiresAt = $expiresAt;
    }

    public function decode(string $stored): ?RunState
    {
        // What an older or foreign writer left is no run of this kind.
        if (self::GONE === $stored) {
            return null;
        }
        $row = json_decode($stored, true, 8, \JSON_THROW_ON_ERROR);
        if (!\is_array($row) || !\is_string($row['id'] ?? null)) {
            throw new \UnexpectedValueException('Not a test run.');
        }

        return new self(
            (string) $row['id'],
            (string) $row['status'],
            array_values(array_map('strval', (array) $row['done'])),
            (int) $row['paidCalls'],
            (int) $row['expected'],
            (int) $row['checkpoint'],
            (int) $row['updated'],
        );
    }
}
