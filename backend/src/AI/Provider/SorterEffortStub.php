<?php

declare(strict_types=1);

namespace App\AI\Provider;

/**
 * The test provider's vote for BEFFORT / BEFFORT_WHY, mirroring rule 6c of
 * tools:sort: an explicit wish for depth or a comparison is research, a
 * web-search question is a lookup, everything else one answer. The rating
 * is a required (nullable) field of the sorter schema, so the stub has to
 * vote like a real sorter would.
 */
final class SorterEffortStub
{
    /**
     * @return array{string, string}
     */
    public static function vote(string $text, bool $webSearch): array
    {
        if (preg_match('/\b(kurz|short|briefly|quickly|schnell|en bref|kısaca|brevemente|tl;dr)\b/u', $text)) {
            return ['answer', 'asked_short'];
        }
        if (preg_match('/\b(gründlich|ausführlich|recherchier\w*|thorough\w*|in detail|with sources|research this|à fond|detalladamente|ayrıntılı)\b/u', $text)) {
            return ['research', 'asked_thorough'];
        }
        if (preg_match('/\b(vergleich\w*|compar\w*|karşılaştır\w*)\b/u', $text)) {
            return ['research', 'comparison'];
        }
        if (preg_match('/\b(behörden|welche dokumente|which offices|which documents|step by step|in welcher reihenfolge)\b/u', $text)) {
            return ['research', 'procedure'];
        }

        return $webSearch ? ['lookup', 'current_fact'] : ['answer', 'simple'];
    }
}
