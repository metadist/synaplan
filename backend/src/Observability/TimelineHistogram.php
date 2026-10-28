<?php

declare(strict_types=1);

namespace App\Observability;

/**
 * Fixed duration histogram buckets (in milliseconds) and percentile computation
 * for request and AI call timing aggregates (`04_capacity_and_kubernetes.md` §2, S1 AU13).
 */
final class TimelineHistogram
{
    /** @var list<int> bucket upper bounds in milliseconds (`+Inf` is the trailing bucket) */
    public const BUCKETS_MS = [1, 5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000, 30000, 60000];

    private function __construct()
    {
    }

    public static function bucketKey(int|float $durationMs): string
    {
        foreach (self::BUCKETS_MS as $bound) {
            if ($durationMs <= $bound) {
                return (string) $bound;
            }
        }

        return '+Inf';
    }

    /**
     * Estimate a percentile (e.g. `0.50`, `0.95`, `0.99`) from a map of
     * `{bucketKey => count}` histogram counts.
     *
     * @param array<int|string, int> $buckets
     */
    public static function percentile(array $buckets, float $quantile): int
    {
        $total = 0;
        foreach (self::BUCKETS_MS as $bound) {
            $total += (int) ($buckets[$bound] ?? $buckets[(string) $bound] ?? 0);
        }
        $total += (int) ($buckets['+Inf'] ?? 0);

        if ($total <= 0) {
            return 0;
        }

        $target = (int) max(1, (int) ceil($total * $quantile));
        $cumulative = 0;

        foreach (self::BUCKETS_MS as $bound) {
            $cumulative += (int) ($buckets[$bound] ?? $buckets[(string) $bound] ?? 0);
            if ($cumulative >= $target) {
                return $bound;
            }
        }

        return self::BUCKETS_MS[\count(self::BUCKETS_MS) - 1];
    }
}
