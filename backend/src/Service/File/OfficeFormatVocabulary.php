<?php

declare(strict_types=1);

namespace App\Service\File;

/**
 * The single word list behind every "which office format?" decision.
 *
 * Three call sites read it with different strictness, on purpose:
 * - FileGenerationEnvelope::requestedFormat elects the OUTPUT format and must
 *   stay precise ("in other words" is not Word, "from this Excel" is a source).
 * - The fast-path classifier only decides sorter-or-not; a false positive
 *   costs one sorter call, so it errs generous.
 * - DocumentEditCoordinator::inferKind seeds an empty tool session and must
 *   never silently birth workbook.xlsx for a presentation request.
 *
 * Add a new synonym here, not in one of the three call sites.
 */
final class OfficeFormatVocabulary
{
    private function __construct()
    {
    }

    /**
     * Strict output signals per canonical extension, as PCRE bodies without
     * delimiters. Bare "word" stays out ("in other words"); bare "tabelle"
     * stays out (often a markdown table, not a file).
     *
     * @return array<string, string>
     */
    public static function patterns(): array
    {
        return [
            'xlsx' => '\b\.?xlsx?\b|\bexcel\b|\bspreadsheet\b|\btabellenkalkulation\b',
            'docx' => '\b\.?docx?\b|word-dokument|word-datei|microsoft word|\bals word\b|\bas word\b|\bword document\b',
            // "Power Point" is two words in casual German and English.
            // "Folien" is the everyday word for slides.
            'pptx' => '\b\.?pptx?\b|power\s*points?|slides?|\bdeck\b|folien?\b|foliensatz|präsentation|praesentation|presentation',
            'csv' => '\b\.?csv\b',
            // Extension only. The phrase "text file" is matched separately so
            // "text file … counter_weekday.txt" still elects txt.
            'txt' => '\.txt\b',
            'md' => '\.md\b',
        ];
    }

    /**
     * Recall-oriented alternation for the fast-path classifier (no delimiters).
     * A hit only defers to the AI sorter, so generosity is safe.
     */
    public static function fastPathAlternation(): string
    {
        return 'docx|xlsx|pptx|csv|word|excel|power\s*point|powerpoint|spreadsheet|tabellenkalkulation|praesentation|präsentation|presentation|folie|folien|foliensatz|slides?|deck';
    }

    /**
     * Weak kind hints for a new tool session when the strict election found
     * nothing ("mach ein Dokument" still seeds Word, "mach ein paar Slides"
     * still seeds a deck). Never used to override an elected format.
     *
     * @return array<string, string> canonical extension => PCRE body
     */
    public static function kindHints(): array
    {
        return [
            'xlsx' => 'workbook|tabelle',
            'pptx' => 'slides?|deck',
            'docx' => 'documents?|dokumente?',
        ];
    }

    /**
     * Whether the word itself names a format. Used to chain source mentions:
     * "from this Excel spreadsheet" continues the source named just before it,
     * while "von CSV nach Excel" does not ("nach" is no format word).
     */
    public static function isFormatWord(string $word): bool
    {
        return 1 === preg_match(
            '/^(xlsx?|excel|spreadsheet|tabellenkalkulation|tabelle|workbook|csv|docx?|word|dokumente?|documents?|pptx?|powerpoints?|presentation|präsentation|praesentation|folie|folien|foliensatz|slides?|deck)$/iu',
            $word,
        );
    }
}
