<?php

namespace App\Service\File;

/**
 * Text Cleaner Utility.
 *
 * Cleans and normalizes extracted text from documents.
 */
class TextCleaner
{
    /**
     * Clean and normalize text.
     */
    public function clean(string $text): string
    {
        // Remove excessive whitespace
        $text = preg_replace('/[\x00-\x08\x0B-\x0C\x0E-\x1F\x7F]/u', '', $text);

        // Normalize line breaks
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Remove multiple consecutive newlines (max 2)
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        // Remove trailing whitespace from each line
        $lines = explode("\n", $text);
        $lines = array_map('rtrim', $lines);
        $text = implode("\n", $lines);

        // Trim overall
        $text = trim($text);

        return $text;
    }

    /**
     * Calculate Shannon entropy of text (quality check).
     */
    public function shannonEntropy(string $text): float
    {
        $len = strlen($text);
        if (0 === $len) {
            return 0.0;
        }

        $freq = [];
        for ($i = 0; $i < $len; ++$i) {
            $char = $text[$i];
            $freq[$char] = ($freq[$char] ?? 0) + 1;
        }

        $entropy = 0.0;
        foreach ($freq as $count) {
            $p = $count / $len;
            $entropy -= $p * log($p, 2);
        }

        return $entropy;
    }

    /**
     * Check if text is low quality (too short or low entropy).
     */
    public function isLowQuality(string $text, int $minLength, float $minEntropy): bool
    {
        if (mb_strlen($text) < $minLength) {
            return true;
        }

        $entropy = $this->shannonEntropy($text);

        return $entropy < $minEntropy;
    }
}
