<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Ledger;

/**
 * Splits extracted source text into stable numbered passages before a model sees it.
 * The same text and the same limit always produce the same ids.
 */
final readonly class PassageSplitter
{
    /**
     * @throws \InvalidArgumentException when the limit is outside 200–2000 characters
     */
    public function split(string $rawText, int $maxChars): PassageMap
    {
        if ($maxChars < 200 || $maxChars > 2000) {
            throw new \InvalidArgumentException('A passage is between 200 and 2000 characters.');
        }

        $text = str_replace(["\r\n", "\r"], "\n", trim($rawText));
        if ('' === $text) {
            return new PassageMap([]);
        }

        $blocks = preg_split("/\n\s*\n/u", $text) ?: [];
        $pieces = [];
        foreach ($blocks as $block) {
            foreach ($this->piecesOf(trim((string) $block), $maxChars) as $piece) {
                $pieces[] = $piece;
            }
        }

        $passages = [];
        foreach ($pieces as $index => $piece) {
            $passages[] = new Passage('§'.($index + 1), $piece);
        }

        return new PassageMap($passages);
    }

    /**
     * @return list<string>
     */
    private function piecesOf(string $block, int $maxChars): array
    {
        if ('' === $block) {
            return [];
        }

        $lines = preg_split("/\n/u", $block) ?: [];
        $pieces = [];
        $prose = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ('' === $line) {
                continue;
            }
            if ($this->isRow($line)) {
                array_push($pieces, ...$this->flushProse($prose, $maxChars));
                $prose = [];
                $pieces[] = $line;
                continue;
            }
            $prose[] = $line;
        }
        array_push($pieces, ...$this->flushProse($prose, $maxChars));

        return $pieces;
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function flushProse(array $lines, int $maxChars): array
    {
        if ([] === $lines) {
            return [];
        }

        return $this->sentences(implode(' ', $lines), $maxChars);
    }

    /**
     * @return list<string>
     */
    private function sentences(string $paragraph, int $maxChars): array
    {
        if (mb_strlen($paragraph) <= $maxChars) {
            return [$paragraph];
        }

        $parts = preg_split('/(?<=[.!?])\s+/u', $paragraph, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts) || count($parts) < 2) {
            return [$paragraph];
        }

        $sentences = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ('' !== $part) {
                $sentences[] = $part;
            }
        }

        return $sentences;
    }

    private function isRow(string $line): bool
    {
        if (str_starts_with($line, '|') && str_contains(substr($line, 1), '|')) {
            return true;
        }

        return 1 === preg_match('/^(?:[-*•]|\d+[.)])\s+\S/u', $line);
    }
}
