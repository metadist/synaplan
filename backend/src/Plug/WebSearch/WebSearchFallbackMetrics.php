<?php

declare(strict_types=1);

namespace App\Plug\WebSearch;

use Psr\Log\LoggerInterface;

/**
 * Counts one-shot web-search fallbacks. There is no Prometheus stack in this
 * repo; the structured log name matches the planned metric
 * `synaplan_plugs_web_search_fallback_total{from,to}`.
 */
final class WebSearchFallbackMetrics
{
    /** @var array<string, int> */
    private array $counts = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function increment(string $from, string $to): void
    {
        $key = $from."\t".$to;
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
        $this->logger->info('synaplan_plugs_web_search_fallback_total', [
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * Every chat search — success, fallback, or empty — so a broken provider
     * is not indistinguishable from "the web had nothing".
     */
    public function recordResolved(string $provider, int $results, ?string $fellBackFrom): void
    {
        $this->logger->info('synaplan_plugs_web_search_resolved', [
            'provider' => $provider,
            'results' => $results,
            'fellBackFrom' => $fellBackFrom,
            'empty' => 0 === $results,
        ]);
    }

    public function recordFailure(string $provider, string $reason): void
    {
        $this->logger->warning('synaplan_plugs_web_search_failed', [
            'provider' => $provider,
            'reason' => $reason,
        ]);
    }

    public function count(string $from, string $to): int
    {
        return $this->counts[$from."\t".$to] ?? 0;
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }
}
