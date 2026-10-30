<?php

declare(strict_types=1);

namespace App\Service\Egress;

use App\Service\Infrastructure\RedisService;

/**
 * Counts outbound calls by allowlisted destination and outcome.
 * `destination` is the matched rule (`api.openai.com`, `*.example.org`),
 * or `other`. The same counters are
 * written to Redis when it is up, under `egress:req:{destination}:{outcome}`.
 */
final class EgressMetrics
{
    public const OUTCOMES = ['allowed', 'would_block', 'refused'];

    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<string, int> */
    private array $durationMs = [];

    public function __construct(
        private readonly ?RedisService $redis = null,
    ) {
    }

    public function record(string $destination, string $outcome, int $durationMs = 0): void
    {
        if (1 !== preg_match('/^[a-z0-9.:_*-]+$/', $destination)) {
            $destination = 'other';
        }
        if (!\in_array($outcome, self::OUTCOMES, true)) {
            $outcome = 'refused';
        }
        $field = $destination.'|'.$outcome;
        $this->counts[$field] = ($this->counts[$field] ?? 0) + 1;
        $this->durationMs[$field] = ($this->durationMs[$field] ?? 0) + max(0, $durationMs);
        $this->redis?->increment('egress:req:'.$destination.':'.$outcome);
    }

    public function count(string $destination, string $outcome): int
    {
        return $this->counts[$destination.'|'.$outcome] ?? 0;
    }

    /**
     * Prometheus text for the counters this process has seen.
     */
    public function prometheus(): string
    {
        $lines = [
            '# TYPE synaplan_egress_requests_total counter',
            '# TYPE synaplan_egress_duration_seconds counter',
        ];
        foreach ($this->counts as $field => $count) {
            [$destination, $outcome] = explode('|', $field, 2);
            $labels = 'destination="'.$destination.'",outcome="'.$outcome.'"';
            $lines[] = 'synaplan_egress_requests_total{'.$labels.'} '.$count;
            $seconds = number_format(($this->durationMs[$field] ?? 0) / 1000, 3, '.', '');
            $lines[] = 'synaplan_egress_duration_seconds{'.$labels.'} '.$seconds;
        }

        return implode("\n", $lines)."\n";
    }
}
