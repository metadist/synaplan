<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Research\Run\Ledger\PassageText;

/**
 * Decides whether a thorough revision may replace the removal. The revision
 * may shorten or drop. It may not add a fact the draft did not have, leave a
 * rejected statement in the factual text, or keep a contradicted statement
 * cited as a second version.
 */
final readonly class CheckRevision
{
    public function __construct(private DraftCheck $draft)
    {
    }

    /**
     * @param array<string, string>                                                                          $passages
     * @param array{drop: list<array{sentence: string, text: string}>, ask: list<array{id: string, sentence: string, bare: string, passages: string}>} $sorted
     * @param array<string, array{verdict: string, part: string}>                                            $verdicts
     */
    public function acceptable(string $before, string $after, array $passages, array $sorted, array $verdicts): bool
    {
        $after = trim($after);
        $body = CheckReport::withoutNotConfirmed($after);
        if ('' === $after || $this->draft->revisionAddsIssue(CheckReport::withoutNotConfirmed($before), $body, $passages)) {
            return false;
        }
        $listed = CheckReport::notConfirmedBody($after);
        $unchanged = $this->bareSentences(CheckReport::withoutNotConfirmed($before));
        foreach ($sorted['drop'] as $item) {
            if ($this->contains($body, $item['text'])) {
                return false;
            }
        }
        foreach ($sorted['ask'] as $item) {
            $verdict = $verdicts[$item['id']]['verdict'] ?? 'unsupported';
            if ('supported' === $verdict) {
                if (!$this->contains($body, $item['bare'])) {
                    return false;
                }
                continue;
            }
            if ($this->contains($body, $item['bare'])) {
                return false;
            }
            if ('contradicted' === $verdict && $this->citedAgain($listed, $item['bare'], $item['sentence'])) {
                return false;
            }
            if ('partial' === $verdict && !$this->keepsPartial($body, $item, $verdicts[$item['id']]['part'] ?? '', $unchanged)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{id: string, sentence: string, bare: string, passages: string} $item
     * @param array<string, true>                                                $unchanged
     */
    private function keepsPartial(string $body, array $item, string $unsupportedPart, array $unchanged): bool
    {
        $part = trim($unsupportedPart);
        if ('' !== $part && $this->contains($body, $part)) {
            return false;
        }

        return $this->keepsCitation($body, $item['sentence'], $unchanged);
    }

    /**
     * The softened statement is a sentence of the revision that is not one
     * of the draft's sentences and still cites the evidence of the partial.
     *
     * @param array<string, true> $unchanged
     */
    private function keepsCitation(string $body, string $sentence, array $unchanged): bool
    {
        $ids = $this->ids($sentence);
        foreach (DraftSentences::split($body) as $revised) {
            if (isset($unchanged[$this->bare($revised)])) {
                continue;
            }
            if ([] !== array_intersect($ids, $this->ids($revised))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, true>
     */
    private function bareSentences(string $report): array
    {
        $out = [];
        foreach (DraftSentences::split($report) as $sentence) {
            $out[$this->bare($sentence)] = true;
        }

        return $out;
    }

    private function bare(string $sentence): string
    {
        return PassageText::normalise((string) preg_replace(DraftSentences::MARKER, '', $sentence));
    }

    /**
     * @return list<string>
     */
    private function ids(string $sentence): array
    {
        preg_match_all(DraftSentences::MARKER, $sentence, $markers);
        preg_match_all('/e\d+/i', implode(' ', $markers[0]), $ids);

        return array_values(array_unique(array_map('strtolower', $ids[0])));
    }

    /** A contradicted statement listed with its citation is still a second version. */
    private function citedAgain(string $listed, string $bare, string $sentence): bool
    {
        if (!$this->contains($listed, $bare)) {
            return false;
        }
        preg_match_all('/\[e\d+\]/i', $sentence, $ids);
        $listed = mb_strtolower($listed);
        foreach ($ids[0] as $id) {
            if (str_contains($listed, mb_strtolower($id))) {
                return true;
            }
        }

        return false;
    }

    private function contains(string $text, string $needle): bool
    {
        return '' !== $needle && str_contains($text, $needle);
    }
}
