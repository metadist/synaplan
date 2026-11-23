<?php

declare(strict_types=1);

namespace App\Service\Observability;

use App\Observability\RequestTimelineStore;
use App\Observability\ServerIdentity;
use App\Observability\TimelineHistogram;

/**
 * `GET /api/v1/admin/capacity/tools`: tool calls by source and kind over the
 * latest requests of the timeline ring, like the other reports (`04` §4.2, AU33).
 * Counts and timings only; no tool name, argument or result is recorded.
 */
final readonly class CapacityToolsReport
{
    private const WAITING = 'needs_approval';

    public function __construct(
        private RequestTimelineStore $store,
        private ServerIdentity $identity,
    ) {
    }

    /**
     * @return array{
     *     served_by: string,
     *     generated_at: string,
     *     tools: list<array{source: string, kind: string, calls: int, done: int, refused: int, waiting: int, failed: int, p95_ms: int}>
     * }
     *
     * @throws CapacityUnavailableException when Redis does not answer
     */
    public function report(): array
    {
        $records = $this->store->recent();
        if (null === $records) {
            throw CapacityUnavailableException::redisDown();
        }

        /** @var array<string, array{source: string, kind: string, calls: int, done: int, refused: int, waiting: int, failed: int, buckets: array<string, int>}> $rows */
        $rows = [];
        foreach ($records as $record) {
            foreach ($record['tools'] as $tool) {
                $key = $tool['source'].'|'.$tool['kind'];
                $rows[$key] ??= ['source' => $tool['source'], 'kind' => $tool['kind'], 'calls' => 0, 'done' => 0, 'refused' => 0, 'waiting' => 0, 'failed' => 0, 'buckets' => []];
                ++$rows[$key]['calls'];
                ++$rows[$key][self::column($tool['outcome'])];
                $bucket = TimelineHistogram::bucketKey($tool['duration_ms']);
                $rows[$key]['buckets'][$bucket] = ($rows[$key]['buckets'][$bucket] ?? 0) + 1;
            }
        }

        uasort($rows, static fn (array $a, array $b): int => [$b['calls'], $a['source'], $a['kind']] <=> [$a['calls'], $b['source'], $b['kind']]);

        $tools = [];
        foreach ($rows as $row) {
            $tools[] = [
                'source' => $row['source'],
                'kind' => $row['kind'],
                'calls' => $row['calls'],
                'done' => $row['done'],
                'refused' => $row['refused'],
                'waiting' => $row['waiting'],
                'failed' => $row['failed'],
                'p95_ms' => TimelineHistogram::percentile($row['buckets'], 0.95),
            ];
        }

        return [
            'served_by' => $this->identity->id(),
            'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'tools' => $tools,
        ];
    }

    /**
     * @return 'done'|'refused'|'waiting'|'failed'
     */
    private static function column(string $outcome): string
    {
        return match ($outcome) {
            'done' => 'done',
            'refused' => 'refused',
            self::WAITING => 'waiting',
            default => 'failed',
        };
    }
}
