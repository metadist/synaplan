<?php

declare(strict_types=1);

namespace App\Plug\Rerank;

use Psr\Log\LoggerInterface;

/**
 * In-memory + structured-log metrics. There is no Prometheus stack here;
 * log names match the planned series.
 */
final class RerankMetrics
{
    /** @var list<int> */
    private array $latencies = [];

    /** @var array<string, int> */
    private array $fallbacks = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function observeLatency(int $ms): void
    {
        $this->latencies[] = $ms;
        $this->logger->info('synaplan_plugs_rerank_latency_ms', ['ms' => $ms]);
    }

    public function incrementFallback(string $reason): void
    {
        $this->fallbacks[$reason] = ($this->fallbacks[$reason] ?? 0) + 1;
        $this->logger->info('synaplan_plugs_rerank_fallback_total', ['reason' => $reason]);
    }

    public function fallbackCount(string $reason): int
    {
        return $this->fallbacks[$reason] ?? 0;
    }

    /**
     * @return list<int>
     */
    public function latencies(): array
    {
        return $this->latencies;
    }
}
