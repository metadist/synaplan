<?php

declare(strict_types=1);

namespace App\Service\Observability;

use App\Observability\RequestTimelineStore;
use App\Observability\ServerIdentity;
use App\Observability\TimelineHistogram;
use App\Service\Egress\EgressConfig;
use App\Service\Egress\EgressDestinationStanding;

/**
 * `GET /api/v1/admin/capacity/outbound`: outbound calls per destination over
 * the latest requests of the timeline ring, and where each destination
 * stands against the allowlist now (`04` §4.2, journey J-AU-3, AU33).
 * Destinations that are not listed come first: those are what report mode
 * would refuse.
 */
final readonly class CapacityOutboundReport
{
    public function __construct(
        private RequestTimelineStore $store,
        private ServerIdentity $identity,
        private EgressConfig $config,
        private EgressDestinationStanding $standing,
    ) {
    }

    /**
     * @return array{
     *     served_by: string,
     *     generated_at: string,
     *     mode: string,
     *     destinations: list<array{destination: string, requests: int, failures: int, refused: int, p95_ms: int, state: string, rule: ?string, allow_rule: ?string}>
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

        /** @var array<string, array{requests: int, failures: int, refused: int, buckets: array<string, int>}> $rows */
        $rows = [];
        foreach ($records as $record) {
            foreach ($record['egress'] as $call) {
                $key = $call['destination'];
                $rows[$key] ??= ['requests' => 0, 'failures' => 0, 'refused' => 0, 'buckets' => []];
                ++$rows[$key]['requests'];
                if ('5xx' === $call['status_class']) {
                    ++$rows[$key]['failures'];
                } elseif ('blocked' === $call['status_class']) {
                    ++$rows[$key]['refused'];
                }
                $bucket = TimelineHistogram::bucketKey($call['duration_ms']);
                $rows[$key]['buckets'][$bucket] = ($rows[$key]['buckets'][$bucket] ?? 0) + 1;
            }
        }

        $destinations = [];
        foreach ($rows as $destination => $row) {
            $destinations[] = [
                'destination' => (string) $destination,
                'requests' => $row['requests'],
                'failures' => $row['failures'],
                'refused' => $row['refused'],
                'p95_ms' => TimelineHistogram::percentile($row['buckets'], 0.95),
            ] + $this->standing->of((string) $destination);
        }

        usort($destinations, static fn (array $a, array $b): int => [
            EgressDestinationStanding::NOT_LISTED === $b['state'],
            $b['requests'],
            $a['destination'],
        ] <=> [
            EgressDestinationStanding::NOT_LISTED === $a['state'],
            $a['requests'],
            $b['destination'],
        ]);

        return [
            'served_by' => $this->identity->id(),
            'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'mode' => $this->config->mode()->value,
            'destinations' => $destinations,
        ];
    }
}
