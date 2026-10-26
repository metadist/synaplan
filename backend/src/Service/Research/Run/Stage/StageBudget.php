<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Research\Run\ResearchBudget;

/** The few budget numbers a stage prints into a prompt. */
final class StageBudget
{
    /**
     * @return array{pointsMin: int, pointsMax: int, words: int, perSearch: int, minutes: int}
     */
    public static function view(ResearchBudget $budget): array
    {
        $exported = $budget->export();
        $limits = is_array($exported['limits'] ?? null) ? $exported['limits'] : [];
        $extras = is_array($limits['extras'] ?? null) ? $limits['extras'] : [];
        $min = $extras['points_min'] ?? null;
        $max = $extras['points_max'] ?? null;
        $wall = is_numeric($limits['wall_seconds'] ?? null) ? (int) $limits['wall_seconds'] : 60;

        return [
            'pointsMin' => is_numeric($min) ? (int) $min : 1,
            'pointsMax' => is_numeric($max) ? (int) $max : 7,
            'words' => is_numeric($extras['report_words'] ?? null) ? (int) $extras['report_words'] : 1500,
            'perSearch' => is_numeric($extras['results_per_search'] ?? null) ? (int) $extras['results_per_search'] : 8,
            'minutes' => max(1, intdiv($wall, 60)),
        ];
    }
}
