<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Research\Run\Ledger\PassageText;

/**
 * A revision checks a cited sentence again unless the same words with the
 * same citations already stood in the checked part of the previous report.
 * A sentence moved out of Not confirmed, or one that now cites other
 * evidence, is checked again.
 */
final class ChangedSentences
{
    /**
     * @param list<array{id: string, sentence: string, bare: string, passages: string}> $ask
     *
     * @return array{0: list<array{id: string, sentence: string, bare: string, passages: string}>, 1: list<array{id: string, sentence: string, bare: string, passages: string}>}
     */
    public static function split(string $prior, array $ask): array
    {
        $known = [];
        foreach (DraftSentences::split(CheckReport::withoutNotConfirmed($prior)) as $sentence) {
            $key = self::key($sentence);
            if (null !== $key) {
                $known[$key] = true;
            }
        }
        $changed = [];
        $same = [];
        foreach ($ask as $item) {
            $key = self::key($item['sentence']);
            if (null !== $key && isset($known[$key])) {
                $same[] = $item;
            } else {
                $changed[] = $item;
            }
        }

        return [$changed, $same];
    }

    private static function key(string $sentence): ?string
    {
        preg_match_all('/e\d+/i', implode(' ', self::markers($sentence)), $ids);
        $cited = array_values(array_unique(array_map('strtolower', $ids[0])));
        if ([] === $cited) {
            return null;
        }
        sort($cited);
        $bare = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace(DraftSentences::MARKER, '', $sentence)));
        if ('' === $bare) {
            return null;
        }

        return PassageText::normalise($bare).'|'.implode(',', $cited);
    }

    /**
     * @return list<string>
     */
    private static function markers(string $sentence): array
    {
        preg_match_all(DraftSentences::MARKER, $sentence, $markers);

        return $markers[0];
    }
}
