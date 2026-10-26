<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

/**
 * Applies a standard fact-check to a report. Only a supported statement
 * stays. The rest is listed under Not confirmed, and the report ends with
 * the count. A thorough revision, which would keep the supported part of a
 * partial statement, is a later step, so every depth uses this removal.
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
    private const REMOVED = [
        'de' => '%d wurden entfernt, weil keine Quelle sie bestätigt hat.',
        'en' => '%d were removed because no source confirmed them.',
        'es' => '%d se quitaron porque ninguna fuente las confirmó.',
        'fr' => '%d ont été retirées, car aucune source ne les confirme.',
        'tr' => '%d tanesi kaldırıldı, çünkü hiçbir kaynak onları doğrulamadı.',
    ];

    /** @var array<string, string> */
    private const SAME_PROVIDER = [
        'de' => 'Geprüft hat ein Modell desselben Anbieters. In diesem Projekt gibt es keinen zweiten Anbieter.',
        'en' => 'Fact-check by a model of the same provider (no second provider in this project).',
        'es' => 'La revisión la hizo un modelo del mismo proveedor. Este proyecto no tiene un segundo proveedor.',
        'fr' => 'La vérification a été faite par un modèle du même fournisseur. Ce projet n\'a pas de second fournisseur.',
        'tr' => 'Kontrolü aynı sağlayıcının bir modeli yaptı. Bu projede ikinci bir sağlayıcı yok.',
    ];

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

        $report = trim((string) preg_replace("/\n{3,}/u", "\n\n", $report));
        $language = isset(self::HEADING[$language]) ? $language : 'en';
        if ([] !== $listed) {
            $lines = ['', '## '.self::HEADING[$language], ''];
            foreach ($listed as $text) {
                $lines[] = '- '.$text;
            }
            $report .= implode("\n", $lines);
        }
        $report .= "\n\n".sprintf(self::BACKED[$language], $counts['supported'], $counts['statements']);
        if ($counts['removed'] > 0) {
            $report .= ' '.sprintf(self::REMOVED[$language], $counts['removed']);
        }
        if ($sameProvider) {
            $report .= "\n\n".self::SAME_PROVIDER[$language];
        }

        return trim($report);
    }

    private static function cut(string $report, string $sentence): string
    {
        $at = strpos($report, $sentence);
        if (false === $at) {
            return $report;
        }

        return substr_replace($report, '', $at, strlen($sentence));
    }
}
