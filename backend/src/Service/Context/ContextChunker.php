<?php

declare(strict_types=1);

namespace App\Service\Context;

/**
 * Structure-aware splitter for large extracted texts.
 *
 * Preference order for a cut: `## ` heading (sheet / chapter boundary) →
 * blank line (paragraph) → line break → hard cut. Markdown tables get their
 * header rows repeated at the top of every continuation chunk, so a chunk
 * that starts in row 3 500 of a spreadsheet still knows what the columns
 * mean — without that a condenser sees anonymous numbers.
 *
 * Pure, deterministic, no I/O.
 */
final class ContextChunker
{
    /** A chunk smaller than this is merged into its neighbour. */
    private const MIN_CHUNK_CHARS = 400;

    /**
     * @return list<string> non-empty chunks, each ≤ `$maxChars` (except a single
     *                      unbreakable line longer than that, which is hard-cut)
     */
    public function split(string $text, int $maxChars): array
    {
        $maxChars = max(self::MIN_CHUNK_CHARS, $maxChars);
        $text = trim($text);
        if ('' === $text) {
            return [];
        }
        if (mb_strlen($text) <= $maxChars) {
            return [$text];
        }

        $chunks = [];
        foreach ($this->splitOnHeadings($text) as $section) {
            foreach ($this->splitSection($section, $maxChars) as $chunk) {
                $chunks[] = $chunk;
            }
        }

        return $this->mergeTinyChunks($chunks, $maxChars);
    }

    /**
     * @return list<string>
     */
    private function splitOnHeadings(string $text): array
    {
        $parts = preg_split('/\n(?=## )/u', "\n".$text) ?: [$text];
        $sections = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ('' !== $part) {
                $sections[] = $part;
            }
        }

        return [] === $sections ? [$text] : $sections;
    }

    /**
     * @return list<string>
     */
    private function splitSection(string $section, int $maxChars): array
    {
        if (mb_strlen($section) <= $maxChars) {
            return [$section];
        }

        $tableHeader = $this->markdownTableHeader($section);
        $headerLength = null !== $tableHeader ? mb_strlen($tableHeader) + 1 : 0;
        $bodyBudget = max(self::MIN_CHUNK_CHARS, $maxChars - $headerLength);

        $lines = preg_split('/\n/u', $section) ?: [$section];
        $chunks = [];
        $current = '';
        $first = true;

        foreach ($lines as $line) {
            foreach ($this->hardCutLine($line, $bodyBudget) as $piece) {
                $candidate = '' === $current ? $piece : $current."\n".$piece;
                if (mb_strlen($candidate) > $bodyBudget && '' !== $current) {
                    $chunks[] = $this->withHeader($current, $tableHeader, $first);
                    $first = false;
                    $current = $piece;
                    continue;
                }
                $current = $candidate;
            }
        }

        if ('' !== trim($current)) {
            $chunks[] = $this->withHeader($current, $tableHeader, $first);
        }

        return $chunks;
    }

    /**
     * Header + separator of the first Markdown table in the section, or null
     * when the section carries no table.
     */
    private function markdownTableHeader(string $section): ?string
    {
        if (1 !== preg_match('/^(\|[^\n]*\|)\n(\|[\s:|-]+\|)$/mu', $section, $m)) {
            return null;
        }

        return $m[1]."\n".$m[2];
    }

    private function withHeader(string $chunk, ?string $tableHeader, bool $isFirst): string
    {
        $chunk = trim($chunk);
        if ($isFirst || null === $tableHeader || !str_starts_with($chunk, '|')) {
            return $chunk;
        }

        return $tableHeader."\n".$chunk;
    }

    /**
     * @return list<string>
     */
    private function hardCutLine(string $line, int $maxChars): array
    {
        if (mb_strlen($line) <= $maxChars) {
            return [$line];
        }

        $pieces = [];
        $offset = 0;
        $length = mb_strlen($line);
        while ($offset < $length) {
            $pieces[] = mb_substr($line, $offset, $maxChars);
            $offset += $maxChars;
        }

        return $pieces;
    }

    /**
     * @param list<string> $chunks
     *
     * @return list<string>
     */
    private function mergeTinyChunks(array $chunks, int $maxChars): array
    {
        $merged = [];
        foreach ($chunks as $chunk) {
            $last = count($merged) - 1;
            if ($last >= 0
                && mb_strlen($chunk) < self::MIN_CHUNK_CHARS
                && mb_strlen($merged[$last]) + mb_strlen($chunk) + 2 <= $maxChars) {
                $merged[$last] .= "\n\n".$chunk;
                continue;
            }
            $merged[] = $chunk;
        }

        return array_values(array_filter($merged, static fn (string $c): bool => '' !== trim($c)));
    }
}
