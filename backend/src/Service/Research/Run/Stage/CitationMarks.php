<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Research\Run\Ledger\PassageText;

/**
 * Evidence is cited as [e12]. A plan point id (p1) is not a source, in
 * either bracket style. Fullwidth brackets are the same marks.
 */
final class CitationMarks
{
    private const PLAN = '/[\[［【]\s*p\d+(?:\s*,\s*p\d+)*\s*[\]］】]/iu';
    private const EVIDENCE = '/[\[［【]\s*e\d+/iu';
    private const FULLWIDTH_EVIDENCE = '/[［【]\s*(e\d+(?:\s*,\s*e\d+)*)\s*[］】]/iu';

    /**
     * Cited lines leave the Not confirmed section so the check can see them,
     * plan-point marks are dropped, and evidence marks use plain brackets.
     *
     * @return array{report: string, plan: array<string, true>}
     */
    public static function prepare(string $report): array
    {
        $plan = self::planBares($report);

        return ['report' => self::plain(CheckReport::promoteCited($report)), 'plan' => $plan];
    }

    public static function plain(string $report): string
    {
        $report = preg_replace(self::PLAN, '', $report) ?? $report;

        return preg_replace(self::FULLWIDTH_EVIDENCE, '[$1]', $report) ?? $report;
    }

    public static function cites(string $line): bool
    {
        return 1 === preg_match(self::PLAN, $line) || 1 === preg_match(self::EVIDENCE, $line);
    }

    /**
     * Sentences whose only citation is a plan point. The key is the sentence
     * after that mark is removed.
     *
     * @return array<string, true>
     */
    public static function planBares(string $report): array
    {
        $out = [];
        foreach (preg_split("/\r\n|\r|\n/u", $report) ?: [] as $line) {
            if (1 !== preg_match(self::PLAN, $line) || 1 === preg_match(self::EVIDENCE, $line)) {
                continue;
            }
            foreach (DraftSentences::split(self::plain($line)) as $sentence) {
                $bare = PassageText::normalise(DraftSentences::bare($sentence));
                if ('' !== $bare) {
                    $out[$bare] = true;
                }
            }
        }

        return $out;
    }
}
