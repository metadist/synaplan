<?php

declare(strict_types=1);

namespace App\Service\Research\Spike;

/**
 * Splits extracted source text into stable numbered passages before a model sees it.
 */
final class PassageSplitter
{
    public const PARAGRAPH_LIMIT = 600;

    /**
     * @return list<Passage>
     */
    public function split(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        if ('' === $text) {
            return [];
        }

        $blocks = preg_split("/\n\s*\n/u", $text) ?: [];
        $pieces = [];
        foreach ($blocks as $block) {
            foreach ($this->piecesOf(trim($block)) as $piece) {
                $pieces[] = $piece;
            }
        }

        $passages = [];
        foreach ($pieces as $index => $piece) {
            $passages[] = new Passage('§'.($index + 1), $piece);
        }

        return $passages;
    }

    /**
     * @return list<string>
     */
    private function piecesOf(string $block): array
    {
        if ('' === $block) {
            return [];
        }

        $lines = preg_split("/\n/u", $block) ?: [];
        $pieces = [];
        $prose = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            if ($this->isRow($line)) {
                array_push($pieces, ...$this->flushProse($prose));
                $prose = [];
                $pieces[] = $line;
                continue;
            }
            $prose[] = $line;
        }
        array_push($pieces, ...$this->flushProse($prose));

        return $pieces;
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function flushProse(array $lines): array
    {
        if ([] === $lines) {
            return [];
        }

        return $this->sentences(implode(' ', $lines));
    }

    /**
     * @return list<string>
     */
    private function sentences(string $paragraph): array
    {
        if (mb_strlen($paragraph) <= self::PARAGRAPH_LIMIT) {
            return [$paragraph];
        }

        $parts = preg_split('/(?<=[.!?])\s+/u', $paragraph, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts) || count($parts) < 2) {
            return [$paragraph];
        }

        $sentences = [];
        foreach ($parts as $part) {
            $part = trim($part);
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
