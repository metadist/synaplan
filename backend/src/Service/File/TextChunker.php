<?php

namespace App\Service\File;

/**
 * Text Chunker for RAG (Retrieval-Augmented Generation).
 *
 * Splits long text into semantic chunks suitable for vectorization and embedding.
 * Based on legacy BasicAI::chunkify() logic.
 */
final readonly class TextChunker
{
    public function __construct(
        private int $maxChunkSize = 1500,       // Max characters per chunk
        private int $overlapSize = 150,         // Overlap between chunks
        private int $minChunkSize = 200,        // Min chunk size (avoid tiny chunks)
    ) {
    }

    /**
     * Split text into semantic chunks.
     *
     * @param string $text The text to chunk
     *
     * @return array Array of chunks: [['content' => string, 'start_line' => int, 'end_line' => int], ...]
     */
    public function chunkify(string $text): array
    {
        if (empty($text)) {
            return [];
        }

        // Split into lines
        $lines = explode("\n", $text);

        // Expand any line that is larger than a whole chunk into smaller
        // segments (sentence → word → character boundaries). Without this a
        // long text that contains no newlines would collapse into a single
        // oversized chunk. Each segment keeps its original line number so the
        // overlap bookkeeping below still works.
        $segments = [];
        foreach ($lines as $lineNum => $line) {
            foreach ($this->splitLongLine($line) as $segment) {
                $segments[] = ['text' => $segment, 'line' => $lineNum];
            }
        }

        $chunks = [];
        $currentChunk = '';
        $chunkStartLine = 0;
        $chunkEndLine = 0;

        foreach ($segments as $segment) {
            $line = $segment['text'];
            $lineNum = $segment['line'];
            $lineLength = strlen($line);

            // If current chunk + new line would exceed max size
            if (strlen($currentChunk) + $lineLength + 1 > $this->maxChunkSize && strlen($currentChunk) > 0) {
                // Save current chunk if it meets minimum size
                if (strlen($currentChunk) >= $this->minChunkSize) {
                    $chunks[] = [
                        'content' => trim($currentChunk),
                        'start_line' => $chunkStartLine,
                        'end_line' => $chunkEndLine,
                    ];

                    // Start new chunk with overlap
                    $overlapText = $this->getOverlapText($currentChunk);
                    $currentChunk = $overlapText."\n".$line;
                    $chunkStartLine = max(0, $lineNum - $this->getOverlapLines($lines, $lineNum));
                } else {
                    // Chunk too small, just add line
                    $currentChunk .= "\n".$line;
                }
            } else {
                // Add line to current chunk
                if (empty($currentChunk)) {
                    $currentChunk = $line;
                    $chunkStartLine = $lineNum;
                } else {
                    $currentChunk .= "\n".$line;
                }
            }

            $chunkEndLine = $lineNum;
        }

        // Add remaining chunk
        if (strlen(trim($currentChunk)) >= $this->minChunkSize || empty($chunks)) {
            $chunks[] = [
                'content' => trim($currentChunk),
                'start_line' => $chunkStartLine,
                'end_line' => $chunkEndLine,
            ];
        }

        return $chunks;
    }

    /**
     * Split markdown into heading-aware chunks. Same return shape as chunkify().
     * Headings `#`–`###` start a new section; each chunk is prefixed with a
     * breadcrumb (`Report › Q3`). Tables stay together unless larger than
     * maxChunkSize, in which case they split by row with the header repeated.
     *
     * @return list<array{content: string, start_line: int, end_line: int}>
     */
    public function chunkifyMarkdown(string $markdown): array
    {
        if ('' === $markdown) {
            return [];
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $markdown);
        $lines = explode("\n", $normalized);
        $blocks = $this->parseMarkdownBlocks($lines);

        $headingByLevel = [];
        $chunks = [];
        $currentBody = '';
        $chunkStartLine = 0;
        $chunkEndLine = 0;
        $currentPrefix = '';

        foreach ($blocks as $block) {
            if ('heading' === $block['type']) {
                $this->flushMarkdownChunk($chunks, $currentPrefix, $currentBody, $chunkStartLine, $chunkEndLine);
                $currentBody = '';
                $level = $block['level'];
                foreach (array_keys($headingByLevel) as $lvl) {
                    if ($lvl >= $level) {
                        unset($headingByLevel[$lvl]);
                    }
                }
                $headingByLevel[$level] = $block['title'];
                ksort($headingByLevel);
                $currentPrefix = implode(' › ', array_values($headingByLevel));
                $chunkStartLine = $block['start'];
                $chunkEndLine = $block['end'];
                continue;
            }

            if ('table' === $block['type']) {
                $this->flushMarkdownChunk($chunks, $currentPrefix, $currentBody, $chunkStartLine, $chunkEndLine);
                $currentBody = '';
                foreach ($this->chunkMarkdownTable($block, $currentPrefix) as $piece) {
                    $chunks[] = $piece;
                }
                continue;
            }

            foreach ($block['lines'] as $i => $line) {
                $lineNum = $block['start'] + $i;
                $candidate = '' === $currentBody ? $line : $currentBody."\n".$line;
                $prefixed = '' !== $currentPrefix ? $currentPrefix."\n\n".$candidate : $candidate;
                if (strlen($prefixed) > $this->maxChunkSize && '' !== $currentBody) {
                    $this->flushMarkdownChunk($chunks, $currentPrefix, $currentBody, $chunkStartLine, $chunkEndLine);
                    $currentBody = $line;
                    $chunkStartLine = $lineNum;
                } else {
                    if ('' === $currentBody) {
                        $chunkStartLine = $lineNum;
                    }
                    $currentBody = $candidate;
                }
                $chunkEndLine = $lineNum;
            }
        }

        $this->flushMarkdownChunk($chunks, $currentPrefix, $currentBody, $chunkStartLine, $chunkEndLine);

        return $chunks;
    }

    /**
     * @param list<array{content: string, start_line: int, end_line: int}> $chunks
     */
    private function flushMarkdownChunk(
        array &$chunks,
        string $prefix,
        string $body,
        int $startLine,
        int $endLine,
    ): void {
        $trimmed = trim($body);
        if ('' === $trimmed) {
            return;
        }
        $content = '' !== $prefix ? $prefix."\n\n".$trimmed : $trimmed;
        if (strlen($content) < $this->minChunkSize && [] !== $chunks) {
            return;
        }
        $chunks[] = [
            'content' => $content,
            'start_line' => $startLine,
            'end_line' => $endLine,
        ];
    }

    /**
     * @param list<string> $lines
     *
     * @return list<array{type: string, lines: list<string>, start: int, end: int, level?: int, title?: string}>
     */
    private function parseMarkdownBlocks(array $lines): array
    {
        $blocks = [];
        $i = 0;
        $n = count($lines);
        while ($i < $n) {
            $heading = $this->matchMarkdownHeading($lines[$i]);
            if (null !== $heading) {
                $blocks[] = [
                    'type' => 'heading',
                    'level' => $heading['level'],
                    'title' => $heading['title'],
                    'lines' => [$lines[$i]],
                    'start' => $i,
                    'end' => $i,
                ];
                ++$i;
                continue;
            }
            if ($this->isMarkdownTableRow($lines[$i])) {
                $start = $i;
                $tableLines = [];
                while ($i < $n && $this->isMarkdownTableRow($lines[$i])) {
                    $tableLines[] = $lines[$i];
                    ++$i;
                }
                $blocks[] = [
                    'type' => 'table',
                    'lines' => $tableLines,
                    'start' => $start,
                    'end' => $i - 1,
                ];
                continue;
            }
            $start = $i;
            $para = [];
            while ($i < $n && null === $this->matchMarkdownHeading($lines[$i]) && !$this->isMarkdownTableRow($lines[$i])) {
                $para[] = $lines[$i];
                ++$i;
            }
            $blocks[] = [
                'type' => 'text',
                'lines' => $para,
                'start' => $start,
                'end' => max($start, $i - 1),
            ];
        }

        return $blocks;
    }

    /**
     * @return array{level: int, title: string}|null
     */
    private function matchMarkdownHeading(string $line): ?array
    {
        if (1 === preg_match('/^(#{1,3})\s+(.+)$/', trim($line), $matches)) {
            return ['level' => strlen($matches[1]), 'title' => trim($matches[2])];
        }

        return null;
    }

    private function isMarkdownTableRow(string $line): bool
    {
        return str_starts_with(ltrim($line), '|');
    }

    private function isMarkdownTableSeparator(string $line): bool
    {
        return 1 === preg_match('/^\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?$/', trim($line));
    }

    /**
     * @param array{type: string, lines: list<string>, start: int, end: int} $block
     *
     * @return list<array{content: string, start_line: int, end_line: int}>
     */
    private function chunkMarkdownTable(array $block, string $prefix): array
    {
        $lines = $block['lines'];
        if ([] === $lines) {
            return [];
        }
        $full = implode("\n", $lines);
        $content = '' !== $prefix ? $prefix."\n\n".$full : $full;
        if (strlen($content) <= $this->maxChunkSize) {
            return [[
                'content' => $content,
                'start_line' => $block['start'],
                'end_line' => $block['end'],
            ]];
        }

        $hasSep = isset($lines[1]) && $this->isMarkdownTableSeparator($lines[1]);
        $header = $lines[0];
        $separator = $hasSep ? $lines[1] : '';
        $headerBlock = $hasSep ? $header."\n".$separator : $header;
        $dataStart = $hasSep ? 2 : 1;

        $out = [];
        $currentRows = [];
        $rowStart = $block['start'] + $dataStart;
        $rowEnd = $rowStart;

        $emit = function (array $rows, int $start, int $end) use (&$out, $prefix, $headerBlock): void {
            if ([] === $rows) {
                return;
            }
            $body = $headerBlock."\n".implode("\n", $rows);
            $text = '' !== $prefix ? $prefix."\n\n".$body : $body;
            $out[] = ['content' => $text, 'start_line' => $start, 'end_line' => $end];
        };

        $n = count($lines);
        for ($i = $dataStart; $i < $n; ++$i) {
            $row = $lines[$i];
            $trial = [] === $currentRows ? [$row] : array_merge($currentRows, [$row]);
            $trialText = ('' !== $prefix ? $prefix."\n\n" : '').$headerBlock."\n".implode("\n", $trial);
            if (strlen($trialText) > $this->maxChunkSize && [] !== $currentRows) {
                $emit($currentRows, $rowStart, $rowEnd);
                $currentRows = [$row];
                $rowStart = $block['start'] + $i;
            } else {
                if ([] === $currentRows) {
                    $rowStart = $block['start'] + $i;
                }
                $currentRows[] = $row;
            }
            $rowEnd = $block['start'] + $i;
        }
        $emit($currentRows, $rowStart, $rowEnd);

        return $out;
    }

    /**
     * Get overlap text from the end of current chunk.
     */
    private function getOverlapText(string $text): string
    {
        if (strlen($text) <= $this->overlapSize) {
            return $text;
        }

        // Get the last N bytes, but never start in the middle of a multibyte
        // UTF-8 character (mb_strcut snaps to a character boundary; a raw
        // substr(-N) would corrupt the leading character of the overlap).
        $overlap = mb_strcut($text, strlen($text) - $this->overlapSize, null, 'UTF-8');
        $spacePos = strpos($overlap, ' ');

        if (false !== $spacePos && $spacePos < $this->overlapSize / 2) {
            return substr($overlap, $spacePos + 1);
        }

        return $overlap;
    }

    /**
     * Calculate how many lines to overlap.
     */
    private function getOverlapLines(array $lines, int $currentLineNum): int
    {
        $overlapChars = 0;
        $overlapLines = 0;

        for ($i = $currentLineNum - 1; $i >= 0 && $overlapChars < $this->overlapSize; --$i) {
            $overlapChars += strlen($lines[$i]);
            ++$overlapLines;
        }

        return $overlapLines;
    }

    /**
     * Split a single oversized line into pieces no larger than maxChunkSize.
     *
     * Prefers sentence boundaries, then word boundaries, and finally a hard
     * character cut so that continuous text without any natural break points
     * (e.g. copy-pasted paragraphs stripped of newlines) is still split.
     *
     * @return string[]
     */
    private function splitLongLine(string $line): array
    {
        if (strlen($line) <= $this->maxChunkSize) {
            return [$line];
        }

        // Split on sentence boundaries while keeping the terminating punctuation.
        $sentences = preg_split('/(?<=[.!?])\s+/u', $line) ?: [$line];

        $pieces = [];
        $current = '';

        foreach ($sentences as $sentence) {
            if ('' === $sentence) {
                continue;
            }

            // A single sentence may still be too long → fall back to words/chars.
            if (strlen($sentence) > $this->maxChunkSize) {
                if ('' !== $current) {
                    $pieces[] = $current;
                    $current = '';
                }
                foreach ($this->splitByWords($sentence) as $wordPiece) {
                    $pieces[] = $wordPiece;
                }

                continue;
            }

            $candidate = '' === $current ? $sentence : $current.' '.$sentence;
            if (strlen($candidate) > $this->maxChunkSize) {
                $pieces[] = $current;
                $current = $sentence;
            } else {
                $current = $candidate;
            }
        }

        if ('' !== $current) {
            $pieces[] = $current;
        }

        return $pieces;
    }

    /**
     * Split text on word boundaries, hard-cutting words longer than maxChunkSize.
     *
     * @return string[]
     */
    private function splitByWords(string $text): array
    {
        $words = preg_split('/\s+/u', $text) ?: [$text];

        $pieces = [];
        $current = '';

        foreach ($words as $word) {
            if ('' === $word) {
                continue;
            }

            if (strlen($word) > $this->maxChunkSize) {
                if ('' !== $current) {
                    $pieces[] = $current;
                    $current = '';
                }
                // Hard-split an over-long word by byte budget, but never cut
                // through a multibyte UTF-8 character. str_split() splits on raw
                // bytes and can emit invalid UTF-8 (this method works on /u
                // Unicode input), which later breaks json_encode(), DB storage
                // and embeddings. mb_strcut() honours the byte budget while
                // snapping to a character boundary.
                $wordLength = strlen($word);
                $offset = 0;
                while ($offset < $wordLength) {
                    $part = mb_strcut($word, $offset, $this->maxChunkSize, 'UTF-8');
                    if ('' === $part) {
                        // maxChunkSize is smaller than the next character's byte
                        // length, so mb_strcut() can't fit a whole character.
                        // Never drop data: emit that single character (it will
                        // exceed the byte budget) so the loop keeps advancing.
                        $part = mb_substr(mb_strcut($word, $offset, 4, 'UTF-8'), 0, 1, 'UTF-8');
                        if ('' === $part) {
                            break;
                        }
                    }
                    $pieces[] = $part;
                    $offset += strlen($part);
                }

                continue;
            }

            $candidate = '' === $current ? $word : $current.' '.$word;
            if (strlen($candidate) > $this->maxChunkSize) {
                $pieces[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }

        if ('' !== $current) {
            $pieces[] = $current;
        }

        return $pieces;
    }

    /**
     * Chunk by fixed size (alternative simple method).
     */
    public function chunkBySize(string $text, int $chunkSize = 500): array
    {
        $chunks = [];
        $length = strlen($text);

        for ($i = 0; $i < $length; $i += $chunkSize) {
            $chunk = substr($text, $i, $chunkSize);
            if (!empty(trim($chunk))) {
                $chunks[] = [
                    'content' => trim($chunk),
                    'start_line' => (int) ($i / 100), // Approximate line number
                    'end_line' => (int) (($i + strlen($chunk)) / 100),
                ];
            }
        }

        return $chunks;
    }
}
