<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Lightweight per-request performance timer.
 *
 * Tracks named phases in the chat streaming pipeline so the frontend (perf SSE
 * event) and Chrome DevTools (Server-Timing header) can show where wall-clock
 * time is spent. All measurements are wall-clock milliseconds with three
 * decimal places of precision.
 *
 * Designed for fire-and-forget instrumentation: no exceptions, no side effects,
 * always-on. Cost per phase is a microtime() call and an array write (~µs).
 *
 * Usage:
 *
 *     $timer = new PerfTimer();
 *     $timer->start('rag');
 *     // ... do RAG work ...
 *     $timer->stop('rag');
 *     $timer->mark('first_token'); // single point in time
 *
 *     // Later:
 *     $headers = ['Server-Timing' => $timer->toServerTimingHeader()];
 *     $sse->send('perf', $timer->toArray());
 *
 * Phases can be nested and reused — repeated start/stop on the same name
 * accumulates total elapsed time, matching how Server-Timing's `dur` field is
 * interpreted. Marks are recorded as elapsed-from-construction milliseconds.
 */
final class PerfTimer
{
    private float $createdAt;

    /** @var array<string, float> total elapsed milliseconds per phase */
    private array $totals = [];

    /** @var array<string, float> microtime when each open phase started */
    private array $open = [];

    /** @var array<string, float> elapsed-from-start milliseconds per mark */
    private array $marks = [];

    public function __construct()
    {
        $this->createdAt = microtime(true);
    }

    public function start(string $phase): void
    {
        $this->open[$phase] = microtime(true);
    }

    public function stop(string $phase): void
    {
        if (!isset($this->open[$phase])) {
            return;
        }
        $elapsedMs = (microtime(true) - $this->open[$phase]) * 1000.0;
        $this->totals[$phase] = ($this->totals[$phase] ?? 0.0) + $elapsedMs;
        unset($this->open[$phase]);
    }

    /**
     * Record a single point-in-time mark relative to timer construction.
     */
    public function mark(string $name): void
    {
        $this->marks[$name] = (microtime(true) - $this->createdAt) * 1000.0;
    }

    /**
     * Total elapsed milliseconds since the timer was constructed.
     */
    public function elapsedMs(): float
    {
        return (microtime(true) - $this->createdAt) * 1000.0;
    }

    /**
     * Snapshot of accumulated phase totals (ms, rounded to 1 decimal).
     *
     * @return array<string, float>
     */
    public function totals(): array
    {
        $out = [];
        foreach ($this->totals as $phase => $ms) {
            $out[$phase] = round($ms, 1);
        }

        return $out;
    }

    /**
     * Snapshot of marks (ms since construction, rounded to 1 decimal).
     *
     * @return array<string, float>
     */
    public function marks(): array
    {
        $out = [];
        foreach ($this->marks as $name => $ms) {
            $out[$name] = round($ms, 1);
        }

        return $out;
    }

    /**
     * Combined payload suitable for an SSE `perf` event.
     *
     * @return array{phases: array<string, float>, marks: array<string, float>, total_ms: float}
     */
    public function toArray(): array
    {
        return [
            'phases' => $this->totals(),
            'marks' => $this->marks(),
            'total_ms' => round($this->elapsedMs(), 1),
        ];
    }

    /**
     * Format phases as a Server-Timing header value.
     *
     * Example: `auth;dur=12.4, rag;dur=180.7, ttft;dur=842.1`
     *
     * Phase names are sanitized to the Server-Timing token grammar
     * (alpha-numerics, dash and underscore only).
     */
    public function toServerTimingHeader(): string
    {
        $entries = [];
        foreach ($this->totals as $phase => $ms) {
            $token = preg_replace('/[^a-zA-Z0-9_-]/', '_', $phase) ?? $phase;
            $entries[] = sprintf('%s;dur=%.1f', $token, $ms);
        }
        foreach ($this->marks as $name => $ms) {
            $token = preg_replace('/[^a-zA-Z0-9_-]/', '_', 'm_'.$name) ?? $name;
            $entries[] = sprintf('%s;dur=%.1f', $token, $ms);
        }

        return implode(', ', $entries);
    }
}
