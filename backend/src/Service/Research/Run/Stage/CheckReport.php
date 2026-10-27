<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

/**
 * Applies a standard fact-check to a report. Only a supported statement
 * stays. The rest is listed under Not confirmed, and the report ends with
 * the count. The writer already keeps a Not confirmed section for gaps; the
 * removed statements join it instead of opening a second one. A thorough
 * run may replace this with one writer revision; every other depth uses
 * this removal.
 */
final class CheckReport
{
    /** @var array<string, string> */
    private const HEADING = [
        'de' => 'Nicht bestätigt',
        'en' => 'Not confirmed',
        'es' => 'Sin confirmar',
        'fr' => 'Non confirmé',
        'tr' => 'Doğrulanmadı',
    ];

    /** @var array<string, string> */
    private const BACKED = [
        'de' => '%1$d von %2$d Aussagen sind durch eine Quelle belegt.',
        'en' => '%1$d of %2$d statements are backed by a source.',
        'es' => '%1$d de %2$d afirmaciones están respaldadas por una fuente.',
        'fr' => '%1$d affirmations sur %2$d s\'appuient sur une source.',
        'tr' => '%2$d ifadeden %1$d tanesi bir kaynakla destekleniyor.',
    ];

    /** @var array<string, string> */
    private const SOFTENED = [
        'de' => '%d wurden auf den Teil gekürzt, den eine Quelle belegt.',
        'en' => '%d were shortened to the part a source backs.',
        'es' => '%d se acortaron a la parte que respalda una fuente.',
        'fr' => '%d ont été réduites à la partie qu\'une source confirme.',
        'tr' => '%d tanesi bir kaynağın desteklediği kısma kısaltıldı.',
    ];

    /** @var array<string, string> */
    private const REMOVED = [
        'de' => '%d wurden entfernt, weil die Prüfung sie nicht in einer Quelle bestätigen konnte.',
        'en' => '%d were removed because the check could not confirm them in a source.',
        'es' => '%d se quitaron porque la revisión no pudo confirmarlas en una fuente.',
        'fr' => '%d ont été retirées, car la vérification n\'a pas pu les confirmer dans une source.',
        'tr' => '%d tanesi kaldırıldı, çünkü kontrol bunları bir kaynakta doğrulayamadı.',
    ];

    /** @var array<string, string> */
    private const SAME_PROVIDER = [
        'de' => 'Geprüft hat ein Modell desselben Anbieters. In diesem Projekt gibt es keinen zweiten Anbieter.',
        'en' => 'A model of the same provider did the fact-check. This project has no second provider.',
        'es' => 'La revisión la hizo un modelo del mismo proveedor. Este proyecto no tiene un segundo proveedor.',
        'fr' => 'La vérification a été faite par un modèle du même fournisseur. Ce projet n\'a pas de second fournisseur.',
        'tr' => 'Kontrolü aynı sağlayıcının bir modeli yaptı. Bu projede ikinci bir sağlayıcı yok.',
    ];

    private const EMPTY_LIST_ITEM = '/^\s*(?:[-*+•]|\d{1,3}[.)])\s*$/u';
    private const LIST_ITEM = '/^\s*(?:[-*+•]|\d{1,3}[.)])\s+\S/u';

    public static function notConfirmedTitle(string $language): string
    {
        return self::HEADING[$language] ?? self::HEADING['en'];
    }

    /**
     * The report without the writer's own Not confirmed section. What the
     * writer already marks as not confirmed is not checked again.
     */
    public static function withoutNotConfirmed(string $report): string
    {
        $lines = self::lines($report);
        $section = self::section($lines);
        if (null === $section) {
            return $report;
        }
        array_splice($lines, $section['start'], $section['end'] - $section['start']);

        return implode("\n", $lines);
    }

    /**
     * @param list<array{sentence: string, text: string}>                               $dropped
     * @param array<string, string>                                                     $verdicts statement id to verdict
     * @param list<array{id: string, sentence: string, bare: string, passages: string}> $asked
     * @param array{statements: int, supported: int, removed: int}                      $counts
     */
    public static function apply(string $report, array $dropped, array $verdicts, array $asked, string $language, array $counts, bool $sameProvider): string
    {
        $listed = [];
        foreach ($dropped as $item) {
            $report = self::cut($report, $item['sentence']);
            $listed[] = $item['text'];
        }
        foreach ($asked as $item) {
            if ('supported' === ($verdicts[$item['id']] ?? 'unsupported')) {
                continue;
            }
            $report = self::cut($report, $item['sentence']);
            $listed[] = $item['bare'];
        }

        $language = isset(self::HEADING[$language]) ? $language : 'en';

        return self::close(self::list(self::tidy($report), $listed, $language), $language, $counts, $sameProvider);
    }

    /**
     * The Not confirmed section, heading included, or an empty string when
     * the report has none.
     */
    public static function notConfirmedBody(string $report): string
    {
        $lines = self::lines($report);
        $section = self::section($lines);
        if (null === $section) {
            return '';
        }

        $body = array_filter(
            array_slice($lines, $section['start'], $section['end'] - $section['start']),
            static fn (string $line): bool => !self::isClosing($line),
        );

        return implode("\n", $body);
    }

    /** A line that close() appended: the count or the same-provider sentence. */
    private static function isClosing(string $line): bool
    {
        $line = trim($line);
        if (in_array($line, self::SAME_PROVIDER, true)) {
            return true;
        }
        foreach (self::BACKED as $format) {
            $pattern = '/^'.str_replace(['%1\$d', '%2\$d'], '\d+', preg_quote($format, '/')).'/u';
            if (1 === preg_match($pattern, $line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Appends the count, and the same-provider sentence when it applies.
     * The report text itself is left as it is. Softened statements are
     * part of the supported count and are named so the reader knows the
     * text was shortened.
     *
     * @param array{statements: int, supported: int, removed: int} $counts
     */
    public static function close(string $report, string $language, array $counts, bool $sameProvider, int $softened = 0): string
    {
        $language = isset(self::HEADING[$language]) ? $language : 'en';
        $report = trim($report);
        if ($counts['statements'] > 0) {
            $report .= "\n\n".sprintf(self::BACKED[$language], $counts['supported'], $counts['statements']);
            if ($softened > 0) {
                $report .= ' '.sprintf(self::SOFTENED[$language], $softened);
            }
            if ($counts['removed'] > 0) {
                $report .= ' '.sprintf(self::REMOVED[$language], $counts['removed']);
            }
            if ($sameProvider) {
                $report .= "\n\n".self::SAME_PROVIDER[$language];
            }
        }

        return trim($report);
    }

    private static function cut(string $report, string $sentence): string
    {
        $quoted = preg_quote($sentence, '/');
        $line = '/^[ \t]*'.$quoted.'[ \t]*(?:\r?\n|$)/mu';
        if (1 === preg_match($line, $report)) {
            return preg_replace($line, '', $report, 1) ?? $report;
        }

        return preg_replace('/(?<!\S)'.$quoted.'(?!\S)[ \t]*/u', '', $report, 1) ?? $report;
    }

    /** Drops list markers, tables without rows and blank runs a removed sentence left behind. */
    private static function tidy(string $report): string
    {
        $lines = self::lines($report);
        $kept = [];
        foreach ($lines as $index => $line) {
            $line = rtrim($line);
            if (1 === preg_match(self::EMPTY_LIST_ITEM, $line) || self::headerOfEmptyTable($lines, $index)) {
                continue;
            }
            if (self::isTableRow($line) && self::headerOfEmptyTable($lines, $index - 1)) {
                continue;
            }
            $kept[] = $line;
        }

        return trim((string) preg_replace("/\n{3,}/u", "\n\n", implode("\n", $kept)));
    }

    /**
     * A table header whose rule line is followed by no row.
     *
     * @param list<string> $lines
     */
    private static function headerOfEmptyTable(array $lines, int $index): bool
    {
        if ($index < 0 || !self::isTableRow($lines[$index] ?? '') || 1 !== preg_match(DraftSentences::TABLE_RULE, trim($lines[$index + 1] ?? ''))) {
            return false;
        }

        return !self::isTableRow($lines[$index + 2] ?? '');
    }

    private static function isTableRow(string $line): bool
    {
        $line = trim($line);

        return str_starts_with($line, '|') && str_ends_with($line, '|') && strlen($line) > 1;
    }

    /**
     * @param list<string> $listed
     */
    private static function list(string $report, array $listed, string $language): string
    {
        if ([] === $listed) {
            return $report;
        }
        $items = array_map(static fn (string $text): string => '- '.$text, $listed);
        $lines = self::lines($report);
        $section = self::section($lines);
        if (null === $section) {
            return $report."\n\n".implode("\n", ['## '.self::HEADING[$language], '', ...$items]);
        }

        $at = $section['end'];
        while ($at > $section['start'] + 1 && '' === trim($lines[$at - 1])) {
            --$at;
        }
        $insert = 1 === preg_match(self::LIST_ITEM, $lines[$at - 1]) ? $items : ['', ...$items];
        if ($at < count($lines)) {
            $insert[] = '';
        }
        array_splice($lines, $at, 0, $insert);

        return trim((string) preg_replace("/\n{3,}/u", "\n\n", implode("\n", $lines)));
    }

    /**
     * The line range of the writer's Not confirmed section, heading
     * included. It ends at the next heading of the same or a higher level.
     *
     * @param list<string> $lines
     *
     * @return array{start: int, end: int}|null
     */
    private static function section(array $lines): ?array
    {
        foreach ($lines as $index => $line) {
            $level = self::headingLevel($line);
            if (null === $level) {
                continue;
            }
            $end = count($lines);
            for ($next = $index + 1; $next < count($lines); ++$next) {
                if (1 === preg_match('/^\s*(#{1,6})\s/u', $lines[$next], $hashes) && strlen($hashes[1]) <= $level) {
                    $end = $next;
                    break;
                }
            }

            return ['start' => $index, 'end' => $end];
        }

        return null;
    }

    /**
     * The level of a Not confirmed heading in any of the five languages,
     * or null. A bold line on its own counts as a heading below level six.
     */
    private static function headingLevel(string $line): ?int
    {
        $trimmed = trim($line);
        if (1 === preg_match('/^(#{1,6})\s+(.*)$/u', $trimmed, $match)) {
            $level = strlen($match[1]);
            $text = $match[2];
        } elseif (str_starts_with($trimmed, '**')) {
            $level = 7;
            $text = $trimmed;
        } else {
            return null;
        }
        $text = mb_strtolower(trim($text, " \t*_:"));
        foreach (self::HEADING as $title) {
            if (mb_strtolower($title) === $text) {
                return $level;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        return preg_split("/\r\n|\r|\n/u", $text) ?: [];
    }
}
