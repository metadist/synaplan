<?php

declare(strict_types=1);

namespace App\Service\Observability;

use App\Observability\RequestTimelineStore;
use App\Observability\ServerIdentity;
use App\Observability\TimelineHistogram;

/**
 * Builds the `GET /api/v1/admin/capacity/requests` and `.../ai-calls` reports
 * from the Redis request timeline ring (`04_capacity_and_kubernetes.md` §3, §4.2, S1 AU13).
 */
final readonly class CapacityTimelineService
{
    private const SLOWEST_WINDOW_SECONDS = 3600;
    private const SLOWEST_LIMIT = 20;

    public function __construct(
        private RequestTimelineStore $store,
        private ServerIdentity $identity,
    ) {
    }

    /**
     * @return array{
     *     served_by: string,
     *     generated_at: string,
     *     route_classes: list<array{
     *         route_class: string,
     *         requests: int,
     *         errors: int,
     *         busy: int,
     *         p50_ms: int,
     *         p95_ms: int,
     *         p99_ms: int
     *     }>,
     *     slowest: list<array<string, mixed>>
     * }
     *
     * @throws CapacityUnavailableException when Redis does not answer
     */
    public function requestsReport(): array
    {
        $records = $this->store->recent();
        if (null === $records) {
            throw CapacityUnavailableException::redisDown();
        }

        $now = new \DateTimeImmutable();
        $cutoffTs = $now->getTimestamp() - self::SLOWEST_WINDOW_SECONDS;

        /** @var array<string, array{requests: int, errors: int, busy: int, buckets: array<string, int>}> $byRoute */
        $byRoute = [];
        $slowestCandidates = [];

        foreach ($records as $record) {
            $rc = (string) $record['route_class'];
            $sc = (string) $record['status_class'];
            $totalMs = (int) $record['total_ms'];
            $bucket = TimelineHistogram::bucketKey($totalMs);

            $byRoute[$rc] ??= ['requests' => 0, 'errors' => 0, 'busy' => 0, 'buckets' => []];
            ++$byRoute[$rc]['requests'];
            if ('5xx' === $sc) {
                ++$byRoute[$rc]['errors'];
            } elseif ('busy' === $sc) {
                ++$byRoute[$rc]['busy'];
            }
            $byRoute[$rc]['buckets'][$bucket] = ($byRoute[$rc]['buckets'][$bucket] ?? 0) + 1;

            if ((int) $record['ts'] >= $cutoffTs) {
                $slowestCandidates[] = $record;
            }
        }

        ksort($byRoute);
        $routeClasses = [];
        foreach ($byRoute as $rc => $stats) {
            $routeClasses[] = [
                'route_class' => $rc,
                'requests' => $stats['requests'],
                'errors' => $stats['errors'],
                'busy' => $stats['busy'],
                'p50_ms' => TimelineHistogram::percentile($stats['buckets'], 0.50),
                'p95_ms' => TimelineHistogram::percentile($stats['buckets'], 0.95),
                'p99_ms' => TimelineHistogram::percentile($stats['buckets'], 0.99),
            ];
        }

        usort($slowestCandidates, static fn (array $a, array $b): int => $b['total_ms'] <=> $a['total_ms']);
        $slowest = \array_slice($slowestCandidates, 0, self::SLOWEST_LIMIT);

        return [
            'served_by' => $this->identity->id(),
            'generated_at' => $now->format(\DateTimeInterface::ATOM),
            'route_classes' => $routeClasses,
            'slowest' => $slowest,
        ];
    }

    /**
     * @return array{
     *     served_by: string,
     *     generated_at: string,
     *     models: list<array{
     *         provider: string,
     *         model: string,
     *         capability: string,
     *         calls: int,
     *         failures: int,
     *         ttft_p50_ms: ?int,
     *         ttft_p95_ms: ?int,
     *         duration_p95_ms: int,
     *         tokens_in: int,
     *         tokens_out: int,
     *         cost: string,
     *         hooks_ms_p95: float
     *     }>,
     *     sources: list<array{
     *         source: string,
     *         calls: int,
     *         failures: int,
     *         tokens_in: int,
     *         tokens_out: int,
     *         cost: string
     *     }>
     * }
     *
     * @throws CapacityUnavailableException when Redis does not answer
     */
    public function aiCallsReport(): array
    {
        $records = $this->store->recent();
        if (null === $records) {
            throw CapacityUnavailableException::redisDown();
        }

        $now = new \DateTimeImmutable();
        [$byModel, $bySource] = $this->accumulateAiCalls($records);

        return [
            'served_by' => $this->identity->id(),
            'generated_at' => $now->format(\DateTimeInterface::ATOM),
            'models' => $this->formatModelSummaries($byModel),
            'sources' => $this->formatSourceSummaries($bySource),
        ];
    }

    /**
     * @param list<array<string, mixed>> $records
     *
     * @return array{
     *     0: array<string, array{provider: string, model: string, capability: string, calls: int, failures: int, ttft_buckets: array<string, int>, dur_buckets: array<string, int>, tokens_in: int, tokens_out: int, cost: float, hooks: list<float>}>,
     *     1: array<string, array{source: string, calls: int, failures: int, tokens_in: int, tokens_out: int, cost: float}>
     * }
     */
    private function accumulateAiCalls(array $records): array
    {
        /** @var array<string, array{provider: string, model: string, capability: string, calls: int, failures: int, ttft_buckets: array<string, int>, dur_buckets: array<string, int>, tokens_in: int, tokens_out: int, cost: float, hooks: list<float>}> $byModel */
        $byModel = [];
        /** @var array<string, array{source: string, calls: int, failures: int, tokens_in: int, tokens_out: int, cost: float}> $bySource */
        $bySource = [];

        foreach ($records as $record) {
            foreach ($record['ai'] as $ai) {
                $p = (string) $ai['provider'];
                $m = (string) $ai['model'];
                $cap = (string) $ai['capability'];
                $src = (string) $ai['source'];
                $failed = \in_array($ai['outcome'], ['failed', 'refused'], true);

                $key = sprintf('%s|%s|%s', $p, $m, $cap);
                $byModel[$key] ??= [
                    'provider' => $p,
                    'model' => $m,
                    'capability' => $cap,
                    'calls' => 0,
                    'failures' => 0,
                    'ttft_buckets' => [],
                    'dur_buckets' => [],
                    'tokens_in' => 0,
                    'tokens_out' => 0,
                    'cost' => 0.0,
                    'hooks' => [],
                ];
                ++$byModel[$key]['calls'];
                if ($failed) {
                    ++$byModel[$key]['failures'];
                }
                if (null !== $ai['ttft_ms']) {
                    $tb = TimelineHistogram::bucketKey((int) $ai['ttft_ms']);
                    $byModel[$key]['ttft_buckets'][$tb] = ($byModel[$key]['ttft_buckets'][$tb] ?? 0) + 1;
                }
                $db = TimelineHistogram::bucketKey((int) $ai['duration_ms']);
                $byModel[$key]['dur_buckets'][$db] = ($byModel[$key]['dur_buckets'][$db] ?? 0) + 1;
                $byModel[$key]['tokens_in'] += (int) $ai['tokens_in'];
                $byModel[$key]['tokens_out'] += (int) $ai['tokens_out'];
                $byModel[$key]['cost'] += (float) $ai['cost'];
                $byModel[$key]['hooks'][] = (float) $ai['hooks_ms'];

                $bySource[$src] ??= ['source' => $src, 'calls' => 0, 'failures' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'cost' => 0.0];
                ++$bySource[$src]['calls'];
                if ($failed) {
                    ++$bySource[$src]['failures'];
                }
                $bySource[$src]['tokens_in'] += (int) $ai['tokens_in'];
                $bySource[$src]['tokens_out'] += (int) $ai['tokens_out'];
                $bySource[$src]['cost'] += (float) $ai['cost'];
            }
        }

        return [$byModel, $bySource];
    }

    /**
     * @param array<string, array{provider: string, model: string, capability: string, calls: int, failures: int, ttft_buckets: array<string, int>, dur_buckets: array<string, int>, tokens_in: int, tokens_out: int, cost: float, hooks: list<float>}> $byModel
     *
     * @return list<array{provider: string, model: string, capability: string, calls: int, failures: int, ttft_p50_ms: ?int, ttft_p95_ms: ?int, duration_p95_ms: int, tokens_in: int, tokens_out: int, cost: string, hooks_ms_p95: float}>
     */
    private function formatModelSummaries(array $byModel): array
    {
        ksort($byModel);
        $models = [];
        foreach ($byModel as $entry) {
            $hooks = $entry['hooks'];
            sort($hooks);
            $hookIdx = [] !== $hooks ? (int) floor((\count($hooks) - 1) * 0.95) : 0;

            $models[] = [
                'provider' => $entry['provider'],
                'model' => $entry['model'],
                'capability' => $entry['capability'],
                'calls' => $entry['calls'],
                'failures' => $entry['failures'],
                'ttft_p50_ms' => [] !== $entry['ttft_buckets'] ? TimelineHistogram::percentile($entry['ttft_buckets'], 0.50) : null,
                'ttft_p95_ms' => [] !== $entry['ttft_buckets'] ? TimelineHistogram::percentile($entry['ttft_buckets'], 0.95) : null,
                'duration_p95_ms' => TimelineHistogram::percentile($entry['dur_buckets'], 0.95),
                'tokens_in' => $entry['tokens_in'],
                'tokens_out' => $entry['tokens_out'],
                'cost' => number_format($entry['cost'], 6, '.', ''),
                'hooks_ms_p95' => round($hooks[$hookIdx] ?? 0.0, 3),
            ];
        }

        return $models;
    }

    /**
     * @param array<string, array{source: string, calls: int, failures: int, tokens_in: int, tokens_out: int, cost: float}> $bySource
     *
     * @return list<array{source: string, calls: int, failures: int, tokens_in: int, tokens_out: int, cost: string}>
     */
    private function formatSourceSummaries(array $bySource): array
    {
        ksort($bySource);
        $sources = [];
        foreach ($bySource as $entry) {
            $sources[] = [
                'source' => $entry['source'],
                'calls' => $entry['calls'],
                'failures' => $entry['failures'],
                'tokens_in' => $entry['tokens_in'],
                'tokens_out' => $entry['tokens_out'],
                'cost' => number_format($entry['cost'], 6, '.', ''),
            ];
        }

        return $sources;
    }
}
