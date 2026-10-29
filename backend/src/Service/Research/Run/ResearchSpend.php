<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Service\Research\Run\Stage\ResearchCallUsage;

/**
 * Every model call of a research run, summed per role and model: the
 * sorter's triage, the reader on each source, the lead's rounds, the
 * writer, the checker. The reply in the chat shows these rows under the
 * answer, so the tokens and the cost a person sees are the run's, not just
 * the sorting that preceded it.
 *
 * Costs are kept in micro-dollars, like the budget, so sums stay exact.
 */
final class ResearchSpend
{
    /** @var array<string, string> role → the kind the chat's usage lines show */
    private const KIND = [
        'sorting' => 'SORT',
        'sorter' => 'SORT',
        'planning' => 'LEAD',
        'lead' => 'LEAD',
        'reading' => 'READ',
        'reader' => 'READ',
        'writing' => 'WRITE',
        'writer' => 'WRITE',
        'checker' => 'VERIFY',
        'answer' => 'LLM',
    ];

    private const MICRO = 1_000_000;

    /** @var array<string, array{kind: string, model: string, prompt: int, completion: int, total: int, cost_micro: int, price_known: bool}> */
    private array $rows = [];

    public function add(string $role, string $modelKey, ResearchCallUsage $usage): void
    {
        $kind = self::KIND[$role] ?? strtoupper($role);
        $model = '' !== $modelKey ? $modelKey : 'unknown';
        $key = $kind.'|'.$model;
        $row = $this->rows[$key] ?? ['kind' => $kind, 'model' => $model, 'prompt' => 0, 'completion' => 0, 'total' => 0, 'cost_micro' => 0, 'price_known' => true];
        $row['prompt'] += $usage->promptTokens;
        $row['completion'] += $usage->completionTokens;
        $row['total'] += $usage->totalTokens;
        $row['cost_micro'] += (int) round(((float) $usage->chargedCost) * self::MICRO);
        $row['price_known'] = $row['price_known'] && $usage->priceKnown;
        $this->rows[$key] = $row;
    }

    public function isEmpty(): bool
    {
        return [] === $this->rows;
    }

    public function totalTokens(): int
    {
        return array_sum(array_column($this->rows, 'total'));
    }

    /** The charged cost of the whole run, six decimals. */
    public function cost(): string
    {
        return number_format(array_sum(array_column($this->rows, 'cost_micro')) / self::MICRO, 6, '.', '');
    }

    /**
     * The rows in the shape the chat stores under `ai_usage_extra`.
     *
     * @return list<array{promptTokens: int, completionTokens: int, totalTokens: int, cost: string, modelKey: string, kind: string, priceKnown: bool}>
     */
    public function toMessageUsage(): array
    {
        $rows = [];
        foreach ($this->rows as $row) {
            $rows[] = [
                'promptTokens' => $row['prompt'],
                'completionTokens' => $row['completion'],
                'totalTokens' => $row['total'],
                'cost' => number_format($row['cost_micro'] / self::MICRO, 6, '.', ''),
                'modelKey' => $row['model'],
                'kind' => $row['kind'],
                'priceKnown' => $row['price_known'],
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{kind: string, model: string, prompt: int, completion: int, total: int, cost_micro: int, price_known: bool}>
     */
    public function toArray(): array
    {
        return array_values($this->rows);
    }

    public static function fromArray(mixed $rows): self
    {
        $spend = new self();
        if (!is_array($rows)) {
            return $spend;
        }
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['kind'] ?? null) || !is_string($row['model'] ?? null)) {
                continue;
            }
            $spend->rows[$row['kind'].'|'.$row['model']] = [
                'kind' => $row['kind'],
                'model' => $row['model'],
                'prompt' => self::count($row['prompt'] ?? 0),
                'completion' => self::count($row['completion'] ?? 0),
                'total' => self::count($row['total'] ?? 0),
                'cost_micro' => self::count($row['cost_micro'] ?? 0),
                'price_known' => false !== ($row['price_known'] ?? true),
            ];
        }

        return $spend;
    }

    private static function count(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }
}
