<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Service\Run\RunEventLog;
use App\Service\Run\RunKeys;
use App\Service\Run\RunKeyspace;

/**
 * The event log of chat runs at `run:{id}:events`. Only the person who started a
 * run reads it ({@see ChatRunReader}). Events carry what the card shows and the
 * answer's text as it is written, never a tool's full result.
 */
final readonly class ChatRunEvents
{
    public const STARTED = 'run.started';
    public const STEP = 'step';
    public const TEXT = 'text.delta';
    public const TOOL_CALL = 'tool.call';
    public const TOOL_RESULT = 'tool.result';
    public const PLAN = 'plan.updated';
    public const WAITING = 'run.waiting';
    public const RESUMED = 'run.resumed';
    public const ENDED = 'run.ended';
    public const CHECKED = 'run.checked';

    public const NAMES = [self::STARTED, self::STEP, self::TEXT, self::TOOL_CALL, self::TOOL_RESULT, self::PLAN, self::WAITING, self::RESUMED, self::ENDED, self::CHECKED];

    private RunEventLog $log;

    public function __construct(RunKeys $keys)
    {
        $this->log = new RunEventLog($keys, RunKeyspace::runs(), self::NAMES);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \App\Service\Run\RunNotFound when the run is gone
     */
    public function append(string $runId, string $event, array $data = []): int
    {
        return $this->log->append($runId, $event, $data);
    }

    /** Returns once an event after `$after` exists, or after `$blockMs`. */
    public function await(string $runId, int $after, int $blockMs): void
    {
        $this->log->await($runId, $after, $blockMs);
    }

    /**
     * @return list<array{seq: int, event: string, data: array<string, mixed>}>
     */
    public function replay(string $runId, int $after): array
    {
        return $this->log->replay($runId, $after);
    }
}
