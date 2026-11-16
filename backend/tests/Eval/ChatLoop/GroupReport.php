<?php

declare(strict_types=1);

namespace App\Tests\Eval\ChatLoop;

/**
 * The numbers of a run per group: what STATUS.md quotes and what the gates
 * G1–G7 compare against.
 */
final class GroupReport
{
    /**
     * @param list<TurnOutcome> $outcomes
     *
     * @return array<string, array{turns: int, answered: int, failed: int, no_terminal: int, cost_total: float, cost_median: float|null, price_known: bool, first_token_median: float|null, first_token_p95: float|null, total_median: float|null, total_p95: float|null, recall: float|null, statements: int, backed_rate: float|null}>
     */
    public static function summarize(array $outcomes): array
    {
        $byGroup = [];
        foreach ($outcomes as $outcome) {
            $byGroup[$outcome->group][] = $outcome;
        }

        $summary = [];
        foreach ($byGroup as $group => $rows) {
            $answered = array_values(array_filter($rows, static fn (TurnOutcome $o): bool => TurnOutcome::STATE_ANSWERED === $o->state));
            $recalls = array_values(array_filter(array_map(static fn (TurnOutcome $o): ?float => $o->recall, $answered), static fn (?float $r): bool => null !== $r));
            $firstTokens = array_values(array_filter(array_map(static fn (TurnOutcome $o): ?float => $o->firstTokenSeconds, $answered), static fn (?float $s): bool => null !== $s));
            $statements = array_sum(array_map(static fn (TurnOutcome $o): int => $o->statements, $answered));
            $supported = array_sum(array_map(static fn (TurnOutcome $o): int => $o->supported, $answered));

            $summary[$group] = [
                'turns' => \count($rows),
                'answered' => \count($answered),
                'failed' => \count(array_filter($rows, static fn (TurnOutcome $o): bool => TurnOutcome::STATE_FAILED === $o->state)),
                'no_terminal' => \count(array_filter($rows, static fn (TurnOutcome $o): bool => !$o->hasTerminalResult())),
                'cost_total' => array_sum(array_map(static fn (TurnOutcome $o): float => $o->cost, $rows)),
                'cost_median' => self::percentile(array_map(static fn (TurnOutcome $o): float => $o->cost, $answered), 50),
                'price_known' => [] === array_filter($rows, static fn (TurnOutcome $o): bool => !$o->priceKnown),
                'first_token_median' => self::percentile($firstTokens, 50),
                'first_token_p95' => self::percentile($firstTokens, 95),
                'total_median' => self::percentile(array_map(static fn (TurnOutcome $o): float => $o->totalSeconds, $answered), 50),
                'total_p95' => self::percentile(array_map(static fn (TurnOutcome $o): float => $o->totalSeconds, $answered), 95),
                'recall' => [] === $recalls ? null : array_sum($recalls) / \count($recalls),
                'statements' => $statements,
                'backed_rate' => 0 === $statements ? null : $supported / $statements,
            ];
        }

        return $summary;
    }

    /**
     * @param list<TurnOutcome> $outcomes
     */
    public static function markdown(array $outcomes, string $commandLine): string
    {
        $lines = [
            '# Chat-loop corpus',
            '',
            'Command: `'.$commandLine.'`',
            '',
            '| Group | Turns | Answered | Failed | No terminal result | Cost total | Cost median | First token median / p95 (s) | Total median / p95 (s) | Recall | Backed statements |',
            '| ----- | ----- | -------- | ------ | ------------------ | ---------- | ----------- | ---------------------------- | ---------------------- | ------ | ----------------- |',
        ];
        foreach (self::summarize($outcomes) as $group => $row) {
            $lines[] = sprintf(
                '| %s | %d | %d | %d | %d | %s | %s | %s / %s | %s / %s | %s | %s |',
                $group,
                $row['turns'],
                $row['answered'],
                $row['failed'],
                $row['no_terminal'],
                self::money($row['cost_total'], $row['price_known']),
                self::money($row['cost_median'], $row['price_known']),
                self::seconds($row['first_token_median']),
                self::seconds($row['first_token_p95']),
                self::seconds($row['total_median']),
                self::seconds($row['total_p95']),
                self::percent($row['recall']),
                null === $row['backed_rate'] ? 'n/a' : self::percent($row['backed_rate']).' of '.$row['statements'],
            );
        }
        $lines[] = '';
        $lines[] = 'Cost is what the account was charged (provider cost with markup). "no price" means a model without a published price answered, so 0 is not a cost. Recall is the share of the corpus assertions found word for word in the answer. Backed statements come from the research run\'s own fact-check; a turn that did not hand over to research has none.';
        $lines[] = '';
        $lines[] = '| Turn | Group | State | Cost | First token (s) | Total (s) | Recall | Stages | Reason |';
        $lines[] = '| ---- | ----- | ----- | ---- | --------------- | --------- | ------ | ------ | ------ |';
        foreach ($outcomes as $o) {
            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s | %s | %s | %s | %s |',
                $o->id,
                $o->group,
                $o->state,
                self::money($o->cost, $o->priceKnown),
                self::seconds($o->firstTokenSeconds),
                self::seconds($o->totalSeconds),
                self::percent($o->recall),
                implode(' → ', $o->stages),
                str_replace(['|', "\n"], ['/', ' '], mb_substr((string) $o->reason, 0, 160)),
            );
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Linear interpolation between the two nearest ranks.
     *
     * @param array<float> $values
     */
    public static function percentile(array $values, int $percent): ?float
    {
        if ([] === $values) {
            return null;
        }
        $values = array_values($values);
        sort($values);
        $rank = ($percent / 100) * (\count($values) - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);

        return $values[$low] + ($values[$high] - $values[$low]) * ($rank - $low);
    }

    private static function money(?float $value, bool $priceKnown): string
    {
        if (null === $value) {
            return 'n/a';
        }

        return number_format($value, 4).($priceKnown ? '' : ' (no price)');
    }

    private static function seconds(?float $value): string
    {
        return null === $value ? 'n/a' : number_format($value, 2);
    }

    private static function percent(?float $value): string
    {
        return null === $value ? 'n/a' : number_format($value * 100, 1).' %';
    }
}
