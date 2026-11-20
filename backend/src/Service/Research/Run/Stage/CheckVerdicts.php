<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

/**
 * What the checker model is asked and how its answer is read. The research
 * check stage and the check before a chat run is delivered use the same
 * rules, so a statement is judged the same way in both.
 */
final class CheckVerdicts
{
    public const SUPPORTED = 'supported';

    /** @var list<string> */
    public const VERDICTS = [self::SUPPORTED, 'partial', 'unsupported', 'contradicted'];

    /**
     * The checker's user message: every statement with the passages it cites.
     * Both are data the checker reads, never instructions.
     *
     * @param list<array{id: string, sentence: string, bare: string, passages: string}> $group
     */
    public static function ask(array $group): string
    {
        $lines = [];
        foreach ($group as $item) {
            $lines[] = '<statement id="'.$item['id'].'">';
            $lines[] = '<text>'.ResearchPrompts::data($item['bare']).'</text>';
            $lines[] = '<passages>';
            $lines[] = ResearchPrompts::data($item['passages']);
            $lines[] = '</passages>';
            $lines[] = '</statement>';
        }

        return implode("\n", $lines);
    }

    /**
     * Only the statements of this batch count, each with its first verdict.
     * A row that is not usable is skipped, so that statement stays without
     * a verdict.
     *
     * @param list<array{id: string, sentence: string, bare: string, passages: string}> $group
     *
     * @return array<string, array{verdict: string, part: string, note: string}>|null null when the answer has no verdict list at all
     */
    public static function parse(mixed $data, array $group): ?array
    {
        if (!is_array($data) || !is_array($data['verdicts'] ?? null)) {
            return null;
        }
        $asked = array_fill_keys(array_column($group, 'id'), true);
        $out = [];
        foreach ($data['verdicts'] as $row) {
            if (!is_array($row) || !is_string($row['statement'] ?? null) || !is_string($row['verdict'] ?? null)) {
                continue;
            }
            $id = $row['statement'];
            if (!isset($asked[$id]) || isset($out[$id]) || !in_array($row['verdict'], self::VERDICTS, true)) {
                continue;
            }
            $out[$id] = [
                'verdict' => $row['verdict'],
                'part' => is_string($row['unsupported_part'] ?? null) ? $row['unsupported_part'] : '',
                'note' => is_string($row['note'] ?? null) ? $row['note'] : '',
            ];
        }

        return $out;
    }
}
