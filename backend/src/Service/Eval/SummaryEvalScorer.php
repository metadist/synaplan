<?php

declare(strict_types=1);

namespace App\Service\Eval;

/**
 * Deterministic scoring for `app:summary:eval` — no judge model.
 *
 * Metrics (see planning/20260827-conversation-continuity/01_sprint_1_summary_eval.md):
 *  - size:        raw model output must respect the character cap it was given
 *  - retention:   every required probe (substring or `re:` regex) is present
 *  - hallucination: no forbidden probe is present
 *  - structure:   output starts with a markdown heading, no preamble
 *  - language:    stopword/script heuristic; only a CONFIDENT mismatch fails
 */
final readonly class SummaryEvalScorer
{
    private const REGEX_PROBE_PREFIX = 're:';

    /** Minimum stopword hits before the detector commits to a language. */
    private const MIN_STOPWORD_HITS = 3;

    /** Share of Cyrillic letters that marks a Russian-script summary. */
    private const CYRILLIC_RATIO = 0.25;

    /**
     * Distinctive stopwords only — words shared between two supported
     * languages (e.g. "was", "in") are deliberately absent so a single
     * ambiguous token can never flip the detection.
     *
     * @var array<string, list<string>>
     */
    private const STOPWORDS = [
        'en' => ['the', 'and', 'with', 'for', 'that', 'this', 'from', 'are'],
        'de' => ['und', 'der', 'die', 'das', 'nicht', 'eine', 'für', 'mit', 'wird', 'noch', 'wurde'],
        'es' => ['el', 'la', 'que', 'los', 'una', 'para', 'con', 'las'],
        'tr' => ['ve', 'bir', 'için', 'bu', 'ile', 'olarak'],
    ];

    /**
     * @param list<string> $requiredProbes
     * @param list<string> $forbiddenProbes
     */
    public function score(
        string $summary,
        int $summaryMaxChars,
        array $requiredProbes,
        array $forbiddenProbes,
        ?string $expectLanguage,
    ): SummaryEvalScore {
        $summary = trim($summary);
        $chars = mb_strlen($summary);

        $missing = [];
        foreach ($requiredProbes as $probe) {
            if (!$this->probeMatches($probe, $summary)) {
                $missing[] = $probe;
            }
        }

        $forbiddenHits = [];
        foreach ($forbiddenProbes as $probe) {
            if ($this->probeMatches($probe, $summary)) {
                $forbiddenHits[] = $probe;
            }
        }

        $detected = $this->detectLanguage($summary);
        $languageOk = null;
        if (null !== $expectLanguage && '' !== $expectLanguage && null !== $detected) {
            $languageOk = $detected === $expectLanguage;
        }

        return new SummaryEvalScore(
            chars: $chars,
            sizeOk: $chars > 0 && $chars <= $summaryMaxChars,
            missingRequired: $missing,
            forbiddenHits: $forbiddenHits,
            structureOk: $this->structureOk($summary),
            languageOk: $languageOk,
            detectedLanguage: $detected,
        );
    }

    private function probeMatches(string $probe, string $summary): bool
    {
        if (str_starts_with($probe, self::REGEX_PROBE_PREFIX)) {
            $pattern = '/'.str_replace('/', '\/', substr($probe, strlen(self::REGEX_PROBE_PREFIX))).'/iu';

            return 1 === @preg_match($pattern, $summary);
        }

        return false !== mb_stripos($summary, $probe);
    }

    /**
     * The prompt forbids preamble and mandates `## <heading>` sections, so
     * the first non-empty line must itself be a `## <heading>` line — a
     * lone `# Title` or any prose preamble fails.
     */
    private function structureOk(string $summary): bool
    {
        if ('' === $summary) {
            return false;
        }

        $lines = preg_split('/\R/u', $summary) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }

            return 1 === preg_match('/^##\s+\S/u', $line);
        }

        return false;
    }

    private function detectLanguage(string $summary): ?string
    {
        if ('' === $summary) {
            return null;
        }

        $letters = preg_match_all('/\p{L}/u', $summary);
        if (is_int($letters) && $letters > 0) {
            $cyrillic = preg_match_all('/\p{Cyrillic}/u', $summary);
            if (is_int($cyrillic) && ($cyrillic / $letters) > self::CYRILLIC_RATIO) {
                return 'ru';
            }
        }

        $best = null;
        $bestHits = 0;
        $runnerUpHits = 0;
        foreach (self::STOPWORDS as $language => $words) {
            $pattern = '/\b(?:'.implode('|', array_map('preg_quote', $words)).')\b/iu';
            $hits = preg_match_all($pattern, $summary);
            $hits = is_int($hits) ? $hits : 0;

            if ($hits > $bestHits) {
                $runnerUpHits = $bestHits;
                $bestHits = $hits;
                $best = $language;
            } elseif ($hits > $runnerUpHits) {
                $runnerUpHits = $hits;
            }
        }

        if (null === $best || $bestHits < self::MIN_STOPWORD_HITS || $bestHits === $runnerUpHits) {
            return null;
        }

        return $best;
    }
}
