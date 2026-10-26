<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/**
 * Sequenced events for one run. A client asks for everything after the last
 * id it saw and continues from there. Unknown names are refused here; a
 * client still ignores names it does not know. Events expire with the run.
 */
final readonly class ResearchEventLog
{
    /** @var list<string> */
    public const NAMES = [
        'run',
        'brief',
        'question',
        'plan',
        'plan_waiting',
        'search',
        'source',
        'round',
        'point',
        'writing',
        'section',
        'checking',
        'check',
        'complete',
        'stopped',
        'failed',
        'ping',
    ];

    public function __construct(private ResearchRunKeys $keys)
    {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws ResearchRunNotFound when the run expired or was deleted meanwhile
     */
    public function append(string $runId, string $event, array $data): int
    {
        ResearchIds::assert($runId);
        if (!in_array($event, self::NAMES, true)) {
            throw new \InvalidArgumentException('Unknown research event.');
        }

        try {
            $body = json_encode(
                ['event' => $event, 'data' => $data],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (\JsonException) {
            throw new \InvalidArgumentException('A research update could not be stored.');
        }

        return $this->keys->appendEvent($runId, $body) ?? throw new ResearchRunNotFound($runId);
    }

    /**
     * @return list<array{seq: int, event: string, data: array<string, mixed>}>
     */
    public function replay(string $runId, int $after): array
    {
        ResearchIds::assert($runId);
        if ($after < 0) {
            throw new \InvalidArgumentException('Replay starts at zero or a later update.');
        }

        $out = [];
        foreach ($this->keys->range(ResearchKey::events($runId), 0, -1) as $line) {
            $row = ResearchEventRecord::parse($line);
            if (null === $row || $row['seq'] <= $after) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }
}
