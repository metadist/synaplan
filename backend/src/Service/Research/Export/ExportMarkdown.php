<?php

declare(strict_types=1);

namespace App\Service\Research\Export;

/**
 * Makes a reply's Markdown safe to hand to a renderer that loads what it
 * finds. The text comes from a model and the source titles from the web, so
 * neither may make the server fetch a URL or read a file, and neither may
 * become live HTML in a downloaded page.
 *
 * An image becomes a link to the same address: the Word converter would
 * otherwise download it (or read a local path), and a web page would load
 * it from a third party when opened. Raw HTML is shown as text. Code keeps
 * its characters, because a renderer escapes code on its own.
 */
final class ExportMarkdown
{
    private const FENCE = '/^ {0,3}(`{3,}|~{3,})/';
    private const INLINE_CODE = '/(`+)(?:(?!\1).)+?\1/u';
    private const INLINE_IMAGE = '/!\[([^\]\n]*)\]\(\s*<?([^)\s>]*)>?([^)]*)\)/u';
    /** A "<" that does not open an autolink such as <https://example.com>. */
    private const RAW_HTML_OPEN = '/<(?!(?:https?|mailto):[^\s<>]+>)/iu';

    /**
     * For the Word converter, which reads raw HTML and image tags.
     */
    public static function forDocument(string $markdown): string
    {
        return self::outsideCode($markdown, static fn (string $text): string => (string) preg_replace(self::RAW_HTML_OPEN, '&lt;', self::withoutImages($text)));
    }

    /**
     * For the web page. Safe-mode Parsedown escapes raw HTML itself; only
     * images still need to become links.
     */
    public static function forPage(string $markdown): string
    {
        return self::outsideCode($markdown, self::withoutImages(...));
    }

    private static function withoutImages(string $text): string
    {
        $text = (string) preg_replace_callback(self::INLINE_IMAGE, static function (array $match): string {
            $label = '' !== trim($match[1]) ? $match[1] : $match[2];

            return '['.$label.']('.$match[2].$match[3].')';
        }, $text);

        // A reference image ("![alt][id]") keeps its brackets as a plain link.
        return str_replace('![', '\![', $text);
    }

    /**
     * @param callable(string): string $rewrite
     */
    private static function outsideCode(string $markdown, callable $rewrite): string
    {
        $out = [];
        $fence = null;
        foreach (preg_split("/\r\n|\r|\n/u", $markdown) ?: [] as $line) {
            if (null !== $fence) {
                $out[] = $line;
                if (1 === preg_match(self::FENCE, $line, $match) && $match[1][0] === $fence[0] && strlen($match[1]) >= strlen($fence)) {
                    $fence = null;
                }
                continue;
            }
            if (1 === preg_match(self::FENCE, $line, $match)) {
                $fence = $match[1];
                $out[] = $line;
                continue;
            }
            $out[] = self::outsideInlineCode($line, $rewrite);
        }

        return implode("\n", $out);
    }

    /**
     * @param callable(string): string $rewrite
     */
    private static function outsideInlineCode(string $line, callable $rewrite): string
    {
        $parts = preg_split(self::INLINE_CODE, $line, -1, PREG_SPLIT_OFFSET_CAPTURE);
        if (false === $parts || 1 === count($parts)) {
            return $rewrite($line);
        }
        $result = '';
        $cursor = 0;
        foreach ($parts as [$text, $offset]) {
            $result .= substr($line, $cursor, $offset - $cursor).$rewrite($text);
            $cursor = $offset + strlen($text);
        }

        return $result;
    }
}
