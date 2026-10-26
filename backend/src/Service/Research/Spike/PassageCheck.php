<?php

declare(strict_types=1);

namespace App\Service\Research\Spike;

/**
 * Drops a finding whose passage id is missing, and removes a quote that is not in those passages.
 */
final class PassageCheck
{
    /**
     * @param list<Finding> $findings
     * @param list<Passage> $passages
     *
     * @return array{kept: list<Finding>, dropped: int}
     */
    public function apply(array $findings, array $passages): array
    {
        $byId = [];
        foreach ($passages as $passage) {
            $byId[$passage->id] = $passage->text;
        }

        $kept = [];
        $dropped = 0;
        foreach ($findings as $finding) {
            $checked = $this->check($finding, $byId);
            if (null === $checked) {
                ++$dropped;
                continue;
            }
            $kept[] = $checked;
        }

        return ['kept' => $kept, 'dropped' => $dropped];
    }

    /**
     * @param array<string, string> $byId
     */
    private function check(Finding $finding, array $byId): ?Finding
    {
        if ([] === $finding->passageIds) {
            return null;
        }

        $texts = [];
        foreach ($finding->passageIds as $id) {
            if (!isset($byId[$id])) {
                return null;
            }
            $texts[] = $byId[$id];
        }

        $quote = $finding->quote;
        if (null !== $quote && !$this->quoteIn(implode("\n", $texts), $quote)) {
            $quote = null;
        }

        return new Finding($finding->fact, $finding->passageIds, $quote);
    }

    private function quoteIn(string $passage, string $quote): bool
    {
        return str_contains($this->normalise($passage), $this->normalise($quote));
    }

    private function normalise(string $text): string
    {
        $text = mb_strtolower($text);
        $text = str_replace(["\u{201C}", "\u{201D}", "\u{201E}", "\u{00AB}", "\u{00BB}", '"', "'"], '', $text);
        $text = preg_replace('/(\p{L})-\s+(?=\p{L})/u', '$1', $text) ?? $text;
        $text = preg_replace_callback(
            '/(?<!\d)\d{1,3}(?:[.,\s]\d{3})+(?!\d)/u',
            static fn (array $match): string => preg_replace('/[.,\s]/', '', $match[0]) ?? $match[0],
            $text,
        ) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
