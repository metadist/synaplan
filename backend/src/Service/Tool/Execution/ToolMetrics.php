<?php

declare(strict_types=1);

namespace App\Service\Tool\Execution;

/**
 * In-process counter for `synaplan_tool_calls_total`.
 *
 * Labels stay bounded: source, kind and outcome. No user id, no tool
 * arguments, no result text.
 */
final class ToolMetrics
{
    /** @var array<string, int> */
    private array $counts = [];

    public function record(string $source, string $kind, string $outcome): void
    {
        $source = $this->label($source);
        $kind = $this->label($kind);
        $outcome = $this->label($outcome);
        $field = $source.'|'.$kind.'|'.$outcome;
        $this->counts[$field] = ($this->counts[$field] ?? 0) + 1;
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
