<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Research\Run\Ledger\PassageText;

/**
 * A revision checks a cited sentence again only when its words changed.
 * A sentence that is still the same keeps its citation without another model call.
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
        foreach (DraftSentences::split($prior) as $sentence) {
            $bare = trim((string) preg_replace(DraftSentences::MARKER, '', $sentence));
            $bare = trim((string) preg_replace('/\s+/u', ' ', $bare));
            if ('' !== $bare) {
                $known[PassageText::normalise($bare)] = true;
            }
        }
        $changed = [];
        $same = [];
        foreach ($ask as $item) {
            if (isset($known[PassageText::normalise($item['bare'])])) {
                $same[] = $item;
            } else {
                $changed[] = $item;
            }
        }

        return [$changed, $same];
    }
}
