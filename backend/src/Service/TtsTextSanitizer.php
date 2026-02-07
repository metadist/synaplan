<?php

declare(strict_types=1);

namespace App\Service;

/**
 * TTS Text Sanitizer.
 *
 * Strips non-speakable artifacts from AI response text before TTS synthesis.
 * Removes: <think> tags, [Memory:ID] badges, code blocks, markdown formatting, HTML tags.
 *
 * Call TtsTextSanitizer::sanitize($text) BEFORE passing text to AiFacade::synthesize().
 */
final class TtsTextSanitizer
{
    /**
     * Strip non-speakable artifacts from AI response text.
     */
    public static function sanitize(string $text): string
    {
        // 1. Remove <think>...</think> reasoning blocks
        $text = preg_replace('/<think>[\s\S]*?<\/think>/i', '', $text);

        // 2. Remove [Memory:ID] badges
        $text = preg_replace('/\[Memory:\d+\]/', '', $text);

        // 3. Remove code blocks (```...```)
        $text = preg_replace('/```[\s\S]*?```/', '', $text);

        // 4. Remove inline code (`...`)
        $text = preg_replace('/`[^`]+`/', '', $text);

        // 5. Remove markdown links [text](url) → keep text
        $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $text);

        // 6. Remove markdown formatting (**bold**, *italic*, ~~strike~~)
        $text = preg_replace('/\*{1,3}([^*]+)\*{1,3}/', '$1', $text);
        $text = preg_replace('/~~([^~]+)~~/', '$1', $text);

        // 7. Remove headings (## ...)
        $text = preg_replace('/^#{1,6}\s+/m', '', $text);

        // 8. Remove HTML tags
        $text = strip_tags($text);

        // 9. Remove bullet points and list markers
        $text = preg_replace('/^[\s]*[-*•]\s+/m', '', $text);
        $text = preg_replace('/^[\s]*\d+\.\s+/m', '', $text);

        // 10. Remove horizontal rules
        $text = preg_replace('/^[-*_]{3,}$/m', '', $text);

        // 11. Collapse whitespace
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        return $text;
    }
}
