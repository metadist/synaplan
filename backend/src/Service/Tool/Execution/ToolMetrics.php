<?php

declare(strict_types=1);

namespace App\Service\Tool\Execution;

use App\Service\Infrastructure\RedisService;

/**
 * Counter for `synaplan_tool_calls_total`.
 *
 * The process array is what {@see prometheus()} prints. The same count is
 * written to Redis when it is up, under `tool:calls:{source}:{kind}:{outcome}`,
 * so a new worker still has the totals. Labels stay bounded: source, kind
 * and outcome. No user id, no tool arguments, no result text.
 */
final class ToolMetrics
{
    /** @var array<string, int> */
    private array $counts = [];

    public function __construct(
        private readonly ?RedisService $redis = null,
    ) {
    }

    public function record(string $source, string $kind, string $outcome): void
    {
        $source = $this->label($source);
        $kind = $this->label($kind);
        $outcome = $this->label($outcome);
        $field = $source.'|'.$kind.'|'.$outcome;
        $this->counts[$field] = ($this->counts[$field] ?? 0) + 1;
        $this->redis?->increment('tool:calls:'.$source.':'.$kind.':'.$outcome);
    }

    public function count(string $source, string $kind, string $outcome): int
    {
        return $this->counts[$this->label($source).'|'.$this->label($kind).'|'.$this->label($outcome)] ?? 0;
    }

    public function prometheus(): string
    {
        $lines = ['# TYPE synaplan_tool_calls_total counter'];
        foreach ($this->counts as $field => $count) {
            [$source, $kind, $outcome] = explode('|', $field, 3);
            $lines[] = sprintf(
                'synaplan_tool_calls_total{source="%s",kind="%s",outcome="%s"} %d',
                $source,
                $kind,
                $outcome,
                $count,
            );
        }

        return implode("\n", $lines)."\n";
    }

    private function label(string $value): string
    {
        $value = strtolower(trim($value));
        if (1 !== preg_match('/^[a-z0-9_]+$/', $value)) {
            return 'other';
        }

        return $value;
    }
}
