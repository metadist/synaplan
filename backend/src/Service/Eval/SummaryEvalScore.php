<?php

declare(strict_types=1);

namespace App\Service\Eval;

/**
 * Deterministic quality verdict for one generated rolling summary.
 *
 * `languageOk` is tri-state: null means "no expectation or language not
 * confidently detectable" — only a confident mismatch fails the case, so the
 * heuristic can never produce false negatives on short summaries.
 */
final readonly class SummaryEvalScore
{
    /**
     * @param list<string> $missingRequired required probes not found in the summary
     * @param list<string> $forbiddenHits   forbidden probes that ARE present (hallucination signal)
     */
    public function __construct(
        public int $chars,
        public bool $sizeOk,
        public array $missingRequired,
        public array $forbiddenHits,
        public bool $structureOk,
        public ?bool $languageOk,
        public ?string $detectedLanguage,
    ) {
    }

    public function passed(): bool
    {
        return $this->sizeOk
            && [] === $this->missingRequired
            && [] === $this->forbiddenHits
            && $this->structureOk
            && false !== $this->languageOk;
    }

    /** Compact human-readable list of everything that went wrong. */
    public function problems(): string
    {
        $problems = [];
        if (!$this->sizeOk) {
            $problems[] = 0 === $this->chars
                ? 'empty summary'
                : sprintf('size %d chars over cap', $this->chars);
        }
        foreach ($this->missingRequired as $probe) {
            $problems[] = "missing '{$probe}'";
        }
        foreach ($this->forbiddenHits as $probe) {
            $problems[] = "forbidden '{$probe}' present";
        }
        if (!$this->structureOk) {
            $problems[] = 'structure: no leading markdown heading';
        }
        if (false === $this->languageOk) {
            $problems[] = sprintf('language: detected %s', $this->detectedLanguage ?? 'unknown');
        }

        return implode('; ', $problems);
    }
}
