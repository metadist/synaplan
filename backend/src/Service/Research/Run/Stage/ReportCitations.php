<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

/**
 * Keeps only citations to evidence that exists. A run of markers such as
 * "[e3][e7]" or "[e3, e7]" closes one statement; a statement whose markers
 * all point nowhere loses them and no longer counts as backed by a source.
 */
final class ReportCitations
{
    private const GROUP = '/(?:[ \t]*\[\s*e\d+(?:\s*,\s*e\d+)*\s*\])+/i';

    /**
     * @param list<string> $known evidence ids, e.g. "e3"
     *
     * @return array{report: string, statements: int, supported: int}
     */
    public static function clean(string $report, array $known): array
    {
        $allowed = array_fill_keys(array_map('strtolower', $known), true);
        $statements = 0;
        $supported = 0;
        $cleaned = preg_replace_callback(self::GROUP, static function (array $match) use ($allowed, &$statements, &$supported): string {
            ++$statements;
            preg_match_all('/e\d+/i', $match[0], $ids);
            $kept = [];
            foreach ($ids[0] as $id) {
                $id = strtolower($id);
                if (isset($allowed[$id])) {
                    $kept[$id] = '['.$id.']';
                }
            }
            if ([] === $kept) {
                return '';
            }
            ++$supported;

            return ' '.implode('', $kept);
        }, $report);

        return [
            'report' => trim($cleaned ?? $report),
            'statements' => $statements,
            'supported' => $supported,
        ];
    }
}
