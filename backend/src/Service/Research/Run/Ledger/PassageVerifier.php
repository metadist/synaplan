<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Ledger;

/**
 * Checks that a finding points at passages that exist, and that a quote is
 * really in that passage. A missing passage drops the finding. A quote that
 * is not in the passage is removed, and the finding stays.
 */
final readonly class PassageVerifier
{
    /**
     * @param list<string> $passageIds
     */
    public function passagesExist(array $passageIds, PassageMap $map): bool
    {
        if ([] === $passageIds) {
            return false;
        }

        foreach ($passageIds as $id) {
            if (null === $map->text($id)) {
                return false;
            }
        }

        return true;
    }

    public function quoteInPassage(string $quote, string $passageId, PassageMap $map): ?QuoteMatch
    {
        $passage = $map->text($passageId);
        if (null === $passage) {
            return null;
        }

        $needle = PassageText::normalise($quote);
        if ('' === $needle || !str_contains(PassageText::normalise($passage), $needle)) {
            return null;
        }

        return new QuoteMatch($quote, $passageId, true);
    }

    /**
     * @param list<string> $passageTexts
     */
    public function numbersSupported(string $sentence, array $passageTexts): bool
    {
        $wanted = PassageText::numbersIn($sentence);
        if ([] === $wanted) {
            return true;
        }

        $have = PassageText::numbersIn(implode("\n", $passageTexts));
        foreach ($wanted as $number) {
            if (!in_array($number, $have, true)) {
                return false;
            }
        }

        return true;
    }
}
