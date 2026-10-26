<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Research\Run\Ledger\PassageText;

/**
 * Decides whether a thorough revision may replace the removal. Every cited
 * sentence of the revised text must be a supported statement as it stood,
 * or a shortened partial statement that keeps its citation and drops the
 * unsupported part. Anything else that cites a source is rejected, so a
 * reworded unsupported or contradicted statement cannot stay cited. The
 * revision may not add a fact the draft did not have, and the Not confirmed
 * section may not gain a citation.
 */
final readonly class CheckRevision
{
    public function __construct(private DraftCheck $draft)
    {
    }

    /**
     * @param array<string, string>                                                                                                                    $passages
     * @param array{drop: list<array{sentence: string, text: string}>, ask: list<array{id: string, sentence: string, bare: string, passages: string}>} $sorted
     * @param array<string, array{verdict: string, part: string}>                                                                                      $verdicts
     */
    public function acceptable(string $before, string $after, array $passages, array $sorted, array $verdicts): bool
    {
        $body = CheckReport::withoutNotConfirmed(trim($after));
        if ('' === trim($body)
            || $this->draft->revisionAddsIssue(CheckReport::withoutNotConfirmed($before), $body, $passages)
            || $this->listsNewCitation(CheckReport::notConfirmedBody($before), CheckReport::notConfirmedBody($after))) {
            return false;
        }

        $kept = [];
        $keptIds = [];
        $rejected = array_map(static fn (array $item): string => PassageText::normalise($item['text']), $sorted['drop']);
        $partials = [];
        foreach ($sorted['ask'] as $item) {
            $verdict = $verdicts[$item['id']]['verdict'] ?? 'unsupported';
            $bare = PassageText::normalise($item['bare']);
            if ('supported' === $verdict) {
                $kept[$bare] = true;
                $keptIds = [...$keptIds, ...self::ids($item['sentence'])];
                continue;
            }
            $rejected[] = $bare;
            if ('partial' === $verdict) {
                $partials[$item['id']] = [
                    'ids' => self::ids($item['sentence']),
                    'part' => PassageText::normalise($verdicts[$item['id']]['part'] ?? ''),
                ];
            }
        }

        $text = PassageText::normalise((string) preg_replace(DraftSentences::MARKER, '', $body));
        foreach (array_keys($kept) as $bare) {
            if (!str_contains($text, $bare)) {
                return false;
            }
        }
        foreach ($rejected as $bare) {
            if ('' !== $bare && str_contains($text, $bare)) {
                return false;
            }
        }

        $softened = $this->softened($body, $kept, $keptIds, $partials);

        return null !== $softened && count($softened) === count($partials);
    }

    /**
     * The partial statements the revised body keeps in shortened form, or
     * null when a cited sentence is neither kept nor a shortened partial.
     *
     * @param array<string, true>                                   $kept
     * @param list<string>                                          $keptIds
     * @param array<string, array{ids: list<string>, part: string}> $partials
     *
     * @return array<string, true>|null
     */
    private function softened(string $body, array $kept, array $keptIds, array $partials): ?array
    {
        $partialIds = [];
        foreach ($partials as $partial) {
            $partialIds = [...$partialIds, ...$partial['ids']];
        }
        $allowed = array_unique([...$keptIds, ...$partialIds]);

        $softened = [];
        foreach (DraftSentences::split($body) as $sentence) {
            $ids = self::ids($sentence);
            $bare = PassageText::normalise((string) preg_replace(DraftSentences::MARKER, '', $sentence));
            if ([] === $ids || isset($kept[$bare])) {
                continue;
            }
            if ([] !== array_diff($ids, $allowed)) {
                return null;
            }
            $covers = false;
            foreach ($partials as $id => $partial) {
                if ([] === array_intersect($ids, $partial['ids'])) {
                    continue;
                }
                if ('' !== $partial['part'] && str_contains($bare, $partial['part'])) {
                    return null;
                }
                $softened[$id] = true;
                $covers = true;
            }
            if (!$covers) {
                return null;
            }
        }

        return $softened;
    }

    /** A statement moved to Not confirmed is listed without its citation. */
    private function listsNewCitation(string $before, string $after): bool
    {
        $had = [];
        foreach (DraftSentences::split($before) as $sentence) {
            if ([] !== self::ids($sentence)) {
                $had[PassageText::normalise($sentence)] = true;
            }
        }
        foreach (DraftSentences::split($after) as $sentence) {
            if ([] !== self::ids($sentence) && !isset($had[PassageText::normalise($sentence)])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function ids(string $sentence): array
    {
        preg_match_all(DraftSentences::MARKER, $sentence, $markers);
        preg_match_all('/e\d+/i', implode(' ', $markers[0]), $ids);

        return array_values(array_unique(array_map('strtolower', $ids[0])));
    }
}
