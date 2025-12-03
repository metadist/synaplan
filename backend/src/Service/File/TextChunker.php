<?php

namespace App\Service\File;

/**
 * Text Chunker for RAG (Retrieval-Augmented Generation)
 * 
 * Splits long text into semantic chunks suitable for vectorization and embedding.
 * Based on legacy BasicAI::chunkify() logic.
 */
class TextChunker
{
    public function __construct(
        private int $maxChunkSize = 500,        // Max characters per chunk
        private int $overlapSize = 50,          // Overlap between chunks
        private int $minChunkSize = 100         // Min chunk size (avoid tiny chunks)
    ) {}

    /**
     * Split text into semantic chunks
     * 
     * @param string $text The text to chunk
     * @return array Array of chunks: [['content' => string, 'start_line' => int, 'end_line' => int], ...]
     */
    public function chunkify(string $text): array
    {
        if (empty($text)) {
            return [];
        }

        // Split into lines
        $lines = explode("\n", $text);
        $totalLines = count($lines);

        $chunks = [];
        $currentChunk = '';
        $chunkStartLine = 0;
        $chunkEndLine = 0;

        foreach ($lines as $lineNum => $line) {
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
                    $currentChunk = $overlapText . "\n" . $line;
                    $chunkStartLine = max(0, $lineNum - $this->getOverlapLines($lines, $lineNum));
                } else {
                    // Chunk too small, just add line
                    $currentChunk .= "\n" . $line;
                }
            } else {
                // Add line to current chunk
                if (empty($currentChunk)) {
                    $currentChunk = $line;
                    $chunkStartLine = $lineNum;
                } else {
                    $currentChunk .= "\n" . $line;
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
     * Get overlap text from the end of current chunk
     */
    private function getOverlapText(string $text): string
    {
        if (strlen($text) <= $this->overlapSize) {
            return $text;
        }

        // Get last N characters, but try to break at word boundary
        $overlap = substr($text, -$this->overlapSize);
        $spacePos = strpos($overlap, ' ');
        
        if ($spacePos !== false && $spacePos < $this->overlapSize / 2) {
            return substr($overlap, $spacePos + 1);
        }

        return $overlap;
    }

    /**
     * Calculate how many lines to overlap
     */
    private function getOverlapLines(array $lines, int $currentLineNum): int
    {
        $overlapChars = 0;
        $overlapLines = 0;

        for ($i = $currentLineNum - 1; $i >= 0 && $overlapChars < $this->overlapSize; $i--) {
            $overlapChars += strlen($lines[$i]);
            $overlapLines++;
        }

        return $overlapLines;
    }

    /**
     * Chunk by fixed size (alternative simple method)
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
                    'start_line' => (int)($i / 100), // Approximate line number
                    'end_line' => (int)(($i + strlen($chunk)) / 100),
                ];
            }
        }

        return $chunks;
    }
}

