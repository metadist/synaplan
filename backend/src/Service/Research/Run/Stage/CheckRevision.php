<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

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
        if ('' === $after || $this->draft->revisionAddsIssue($before, $after, $passages)) {
            return false;
        }
        $body = CheckReport::withoutNotConfirmed($after);
        $listed = CheckReport::notConfirmedBody($after);
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
            if ('partial' === $verdict && !$this->keepsPartial($body, $item, $verdicts[$item['id']]['part'] ?? '')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{id: string, sentence: string, bare: string, passages: string} $item
     */
    private function keepsPartial(string $body, array $item, string $unsupportedPart): bool
    {
        $part = trim($unsupportedPart);
        if ('' !== $part && $this->contains($body, $part)) {
            return false;
        }

        return $this->keepsCitation($body, $item['sentence']);
    }

    private function keepsCitation(string $body, string $sentence): bool
    {
        preg_match_all('/\[e\d+\]/i', $sentence, $ids);
        $body = mb_strtolower($body);
        foreach ($ids[0] as $id) {
            if (str_contains($body, mb_strtolower($id))) {
                return true;
            }
        }

        return false;
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
