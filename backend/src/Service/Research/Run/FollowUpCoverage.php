<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/**
 * Whether the notes already speak to a follow-up question. The question is
 * covered when at least half of its content words appear in the findings
 * or the previous report. "And in Austria?" after a report on Germany is
 * not covered, so that question searches once.
 */
final class FollowUpCoverage
{
    private const MIN_WORD = 4;

    /** A word matches the notes on this many leading letters, so "prices" finds "price". */
    private const STEM = 5;

    /** Question words of the five app languages that say nothing about the topic. */
    private const STOPWORDS = [
        'about', 'also', 'been', 'being', 'could', 'does', 'doing', 'from', 'give', 'have', 'into', 'just', 'know', 'like',
        'many', 'more', 'much', 'only', 'other', 'over', 'please', 'should', 'show', 'some', 'still', 'tell', 'than',
        'that', 'their', 'them', 'then', 'there', 'they', 'this', 'very', 'were', 'what', 'when', 'where', 'which',
        'will', 'with', 'would', 'your',
        'aber', 'auch', 'bitte', 'dass', 'denn', 'diese', 'dieser', 'dieses', 'dort', 'eine', 'einem', 'einen', 'einer',
        'gibt', 'haben', 'hier', 'kann', 'können', 'mehr', 'nicht', 'noch', 'oder', 'sein', 'sind', 'über', 'warum',
        'welche', 'welcher', 'welches', 'wenn', 'werden', 'wird', 'wurde',
        'como', 'cuál', 'cuáles', 'cuándo', 'desde', 'dónde', 'entre', 'esta', 'este', 'esto', 'para', 'pero', 'porque',
        'sobre', 'también', 'tiene',
        'avec', 'avoir', 'aussi', 'cette', 'comment', 'dans', 'depuis', 'être', 'mais', 'plus', 'pour', 'pourquoi',
        'quel', 'quelle', 'quelles', 'quels', 'sont',
        'bunu', 'daha', 'gibi', 'hangi', 'için', 'kadar', 'nasıl', 'neden', 'nedir', 'nerede', 'olan', 'şimdi', 'veya',
    ];

    public static function covered(string $question, string $notes): bool
    {
        $haystack = mb_strtolower($notes);
        if ('' === trim($haystack)) {
            return false;
        }
        $words = self::words($question);
        if ([] === $words) {
            return true;
        }
        $hits = 0;
        foreach ($words as $word) {
            if (str_contains($haystack, mb_substr($word, 0, max(self::MIN_WORD, min(self::STEM, mb_strlen($word)))))) {
                ++$hits;
            }
        }

        return 2 * $hits >= count($words);
    }

    /**
     * @return list<string>
     */
    private static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $matches);
        $words = [];
        foreach ($matches[0] as $word) {
            if (mb_strlen($word) >= self::MIN_WORD && !in_array($word, self::STOPWORDS, true) && !in_array($word, $words, true)) {
                $words[] = $word;
            }
        }

        return $words;
    }
}
