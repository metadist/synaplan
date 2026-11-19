<?php

declare(strict_types=1);

namespace App\Service\Run;

/**
 * Sequenced events of one kind of run, on their own stream: chat SSE names
 * and payloads are not part of it. A client asks for everything after the
 * last id it saw and continues from there. Names outside the kind's list are
 * refused here; a client still ignores names it does not know. The events
 * expire with the run.
 */
final readonly class RunEventLog
{
    /** An event name ends up on an SSE `event:` line, so it carries no line break. */
    private const NAME_PATTERN = '/^[a-z][a-z0-9_.-]{0,47}$/D';

    /**
     * @param list<non-empty-string> $names the event names this kind of run may record
     */
    public function __construct(
        private RunKeys $keys,
        private RunKeyspace $keyspace,
        private array $names,
    ) {
        if ([] === $names) {
            throw new \InvalidArgumentException('A run event log needs the names it may record.');
        }
        foreach ($names as $name) {
            if (1 !== preg_match(self::NAME_PATTERN, $name)) {
                throw new \InvalidArgumentException('A run event name is a short lowercase word.');
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws RunNotFound when the run expired or was deleted meanwhile
     */
    public function append(string $runId, string $event, array $data): int
    {
        if (!\in_array($event, $this->names, true)) {
            throw new \InvalidArgumentException('Unknown run event.');
        }

        try {
            $body = json_encode(
                ['event' => $event, 'data' => $data],
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
            );
        } catch (\JsonException) {
            throw new \InvalidArgumentException('A run update could not be stored.');
        }

        return $this->keys->appendEvent(
            $this->keyspace->eventSeq($runId),
            $this->keyspace->events($runId),
            $this->keyspace->state($runId),
            $body,
        ) ?? throw new RunNotFound($runId);
    }

    /**
     * @return list<array{seq: int, event: string, data: array<string, mixed>}>
     */
    public function replay(string $runId, int $after): array
    {
        if ($after < 0) {
            throw new \InvalidArgumentException('Replay starts at zero or a later update.');
        }

        $out = [];
        foreach ($this->keys->range($this->keyspace->events($runId), 0, -1) as $line) {
            $row = RunEventRecord::parse($line);
            // Only names this kind records reach a stream; a foreign line cannot open an SSE frame.
            if (null === $row || $row['seq'] <= $after || !\in_array($row['event'], $this->names, true)) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }
}
