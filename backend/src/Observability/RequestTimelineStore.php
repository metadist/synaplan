<?php

declare(strict_types=1);

namespace App\Observability;

use App\Service\Infrastructure\RedisService;

/**
 * Bounded Redis ring buffer (`timeline:requests`, 10,000 records, 24 h TTL)
 * and per-server metrics hash writer (`metrics:{serverId}:*`, 24 h TTL) for
 * the request timeline and AI call timings (`03_contracts.md` §9,
 * `04_capacity_and_kubernetes.md` §2–§3, S1 AU13).
 */
final readonly class RequestTimelineStore
{
    public const RING_KEY = 'timeline:requests';
    public const MAX_ENTRIES = 10000;
    public const TTL_SECONDS = 86400; // 24 h

    public function __construct(
        private RedisService $redis,
    ) {
    }

    /**
     * Append one redacted request record to the Redis ring and update the
     * server's counter/histogram hashes in a single Redis pipeline.
     *
     * @param array<string, mixed> $record
     */
    public function record(array $record): bool
    {
        $normalized = self::allowListRecord($record);
        $encoded = json_encode($normalized, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if (false === $encoded) {
            return false;
        }

        $server = (string) $normalized['server'];
        $routeClass = (string) $normalized['route_class'];
        $statusClass = (string) $normalized['status_class'];
        $totalMs = (int) $normalized['total_ms'];
        $reqBucket = TimelineHistogram::bucketKey($totalMs);

        $hIncrBy = [];
        $hIncrBy[sprintf('metrics:%s:requests', $server)] = [
            sprintf('%s|%s', $routeClass, $statusClass) => 1,
        ];
        $hIncrBy[sprintf('metrics:%s:request_duration:%s', $server, $routeClass)] = [
            $reqBucket => 1,
            'sum_ms' => $totalMs,
            'count' => 1,
        ];

        foreach ($normalized['ai'] as $ai) {
            $p = $ai['provider'];
            $cap = $ai['capability'];
            $src = $ai['source'];
            $out = $ai['outcome'];
            $durMs = $ai['duration_ms'];
            $durBucket = TimelineHistogram::bucketKey($durMs);

            $callsKey = sprintf('metrics:%s:ai_calls', $server);
            $callField = sprintf('%s|%s|%s|%s', $p, $cap, $src, $out);
            $hIncrBy[$callsKey][$callField] = ($hIncrBy[$callsKey][$callField] ?? 0) + 1;

            $durKey = sprintf('metrics:%s:ai_duration:%s|%s', $server, $p, $cap);
            $hIncrBy[$durKey][$durBucket] = ($hIncrBy[$durKey][$durBucket] ?? 0) + 1;
            $hIncrBy[$durKey]['sum_ms'] = ($hIncrBy[$durKey]['sum_ms'] ?? 0) + $durMs;
            $hIncrBy[$durKey]['count'] = ($hIncrBy[$durKey]['count'] ?? 0) + 1;

            if (null !== $ai['ttft_ms']) {
                $ttftBucket = TimelineHistogram::bucketKey($ai['ttft_ms']);
                $ttftKey = sprintf('metrics:%s:ai_ttft:%s|%s', $server, $p, $cap);
                $hIncrBy[$ttftKey][$ttftBucket] = ($hIncrBy[$ttftKey][$ttftBucket] ?? 0) + 1;
                $hIncrBy[$ttftKey]['sum_ms'] = ($hIncrBy[$ttftKey]['sum_ms'] ?? 0) + $ai['ttft_ms'];
                $hIncrBy[$ttftKey]['count'] = ($hIncrBy[$ttftKey]['count'] ?? 0) + 1;
            }

            $tokKey = sprintf('metrics:%s:ai_tokens', $server);
            $inField = sprintf('%s|in', $p);
            $outField = sprintf('%s|out', $p);
            $hIncrBy[$tokKey][$inField] = ($hIncrBy[$tokKey][$inField] ?? 0) + $ai['tokens_in'];
            $hIncrBy[$tokKey][$outField] = ($hIncrBy[$tokKey][$outField] ?? 0) + $ai['tokens_out'];
        }

        return $this->redis->pipelineTimelineRecord(
            ringKey: self::RING_KEY,
            score: (float) $normalized['ts'],
            encodedRecord: $encoded,
            maxEntries: self::MAX_ENTRIES,
            ttlSeconds: self::TTL_SECONDS,
            hashIncrements: $hIncrBy,
        );
    }

    /**
     * Read recent request timeline records from the Redis ring (newest first).
     *
     * @return list<array<string, mixed>>|null null when Redis is unreachable
     */
    public function recent(int $limit = 1000): ?array
    {
        if (!$this->redis->isAvailable()) {
            return null;
        }

        $raw = $this->redis->zRevRangeByScore(self::RING_KEY, '+inf', '-inf', max(1, min(self::MAX_ENTRIES, $limit)));
        $records = [];
        foreach ($raw as $entry) {
            $decoded = json_decode($entry, true);
            if (\is_array($decoded)) {
                $records[] = self::allowListRecord($decoded);
            }
        }

        return $records;
    }

    /**
     * Strict allow-list normalizer (`04_capacity_and_kubernetes.md` §3). Any
     * field outside the allow-list is dropped by construction.
     *
     * @param array<string, mixed> $input
     *
     * @return array{
     *     id: string,
     *     ts: int,
     *     server: string,
     *     route_class: string,
     *     status_class: string,
     *     total_ms: int,
     *     php_ms: int,
     *     wait_ms?: int,
     *     db_ms: int,
     *     db_queries: int,
     *     ai: list<array{provider: string, model: string, capability: string, source: string, ttft_ms: ?int, duration_ms: int, tokens_in: int, tokens_out: int, cost: string, outcome: string, hooks_ms: float}>,
     *     tools: list<array{source: string, kind: string, outcome: string, duration_ms: int}>,
     *     egress: list<array{destination: string, status_class: string, duration_ms: int}>,
     *     user_id: ?int
     * }
     */
    public static function allowListRecord(array $input): array
    {
        $routeClass = \is_string($input['route_class'] ?? null) && \in_array($input['route_class'], RequestTimelineContext::ROUTE_CLASSES, true)
            ? $input['route_class']
            : 'other';
        $statusClass = \is_string($input['status_class'] ?? null) && \in_array($input['status_class'], ['2xx', '4xx', '5xx', 'busy'], true)
            ? $input['status_class']
            : '2xx';

        $aiList = [];
        foreach (\is_array($input['ai'] ?? null) ? $input['ai'] : [] as $ai) {
            if (!\is_array($ai)) {
                continue;
            }
            $aiList[] = [
                'provider' => RequestTimelineContext::boundedLabel((string) ($ai['provider'] ?? ''), 'unknown'),
                'model' => RequestTimelineContext::boundedCatalogModel(\is_string($ai['model'] ?? null) ? $ai['model'] : null),
                'capability' => RequestTimelineContext::boundedLabel((string) ($ai['capability'] ?? ''), 'other'),
                'source' => RequestTimelineContext::boundedLabel((string) ($ai['source'] ?? ''), 'system'),
                'ttft_ms' => isset($ai['ttft_ms']) && is_numeric($ai['ttft_ms']) ? max(0, (int) $ai['ttft_ms']) : null,
                'duration_ms' => max(0, (int) ($ai['duration_ms'] ?? 0)),
                'tokens_in' => max(0, (int) ($ai['tokens_in'] ?? 0)),
                'tokens_out' => max(0, (int) ($ai['tokens_out'] ?? 0)),
                'cost' => number_format(max(0.0, (float) ($ai['cost'] ?? 0)), 6, '.', ''),
                'outcome' => RequestTimelineContext::boundedLabel((string) ($ai['outcome'] ?? ''), 'failed'),
                'hooks_ms' => round(max(0.0, (float) ($ai['hooks_ms'] ?? 0)), 3),
            ];
        }

        $toolList = [];
        foreach (\is_array($input['tools'] ?? null) ? $input['tools'] : [] as $tool) {
            if (!\is_array($tool)) {
                continue;
            }
            $toolList[] = [
                'source' => RequestTimelineContext::boundedLabel((string) ($tool['source'] ?? ''), 'other'),
                'kind' => RequestTimelineContext::boundedLabel((string) ($tool['kind'] ?? ''), 'read'),
                'outcome' => RequestTimelineContext::boundedLabel((string) ($tool['outcome'] ?? ''), 'done'),
                'duration_ms' => max(0, (int) ($tool['duration_ms'] ?? 0)),
            ];
        }

        $egressList = [];
        foreach (\is_array($input['egress'] ?? null) ? $input['egress'] : [] as $egress) {
            if (!\is_array($egress)) {
                continue;
            }
            $egressList[] = [
                'destination' => RequestTimelineContext::boundedLabel((string) ($egress['destination'] ?? ''), 'other'),
                'status_class' => \is_string($egress['status_class'] ?? null) && \in_array($egress['status_class'], ['2xx', '4xx', '5xx', 'blocked'], true)
                    ? $egress['status_class']
                    : '2xx',
                'duration_ms' => max(0, (int) ($egress['duration_ms'] ?? 0)),
            ];
        }

        $out = [
            'id' => (string) preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($input['id'] ?? 'unknown')),
            'ts' => isset($input['ts']) && is_numeric($input['ts']) ? (int) $input['ts'] : time(),
            'server' => RequestTimelineContext::boundedLabel((string) ($input['server'] ?? ''), 'synaplan'),
            'route_class' => $routeClass,
            'status_class' => $statusClass,
            'total_ms' => max(0, (int) ($input['total_ms'] ?? 0)),
            'php_ms' => max(0, (int) ($input['php_ms'] ?? 0)),
            'db_ms' => max(0, (int) ($input['db_ms'] ?? 0)),
            'db_queries' => max(0, (int) ($input['db_queries'] ?? 0)),
            'ai' => $aiList,
            'tools' => $toolList,
            'egress' => $egressList,
            'user_id' => isset($input['user_id']) && is_numeric($input['user_id']) && (int) $input['user_id'] > 0
                ? (int) $input['user_id']
                : null,
        ];
        if (isset($input['wait_ms']) && is_numeric($input['wait_ms'])) {
            $out['wait_ms'] = max(0, (int) $input['wait_ms']);
        }

        return $out;
    }
}
