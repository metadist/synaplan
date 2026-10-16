<?php

declare(strict_types=1);

namespace App\Service\Telegram;

/**
 * AI replies are Markdown; Telegram renders a small HTML subset
 * (parse_mode=HTML). Everything that is not a known construct is escaped,
 * so the output never carries markup the model did not intend.
 */
final class TelegramMessageFormatter
{
    private const PLACEHOLDER = "\x00%d\x00";

    public function toHtml(string $markdown): string
    {
        /** @var list<string> $slots */
        $slots = [];
        $keep = static function (string $html) use (&$slots): string {
            $slots[] = $html;

            return sprintf(self::PLACEHOLDER, count($slots) - 1);
        };

        $text = str_replace("\x00", '', $markdown);

        $text = (string) preg_replace_callback(
            '/```[^\n`]*\n(.*?)```/s',
            static fn (array $m): string => $keep('<pre>'.self::escape(rtrim($m[1], "\n")).'</pre>'),
            $text,
        );
        $text = (string) preg_replace_callback(
            '/`([^`\n]+)`/',
            static fn (array $m): string => $keep('<code>'.self::escape($m[1]).'</code>'),
            $text,
        );
        $text = (string) preg_replace_callback(
            '/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/',
            static fn (array $m): string => $keep(
                '<a href="'.self::escape($m[2], true).'">'.self::escape($m[1]).'</a>'
            ),
            $text,
        );

        $text = self::escape($text);

        $text = (string) preg_replace('/^#{1,6}[ \t]+(.+?)[ \t]*#*$/m', '<b>$1</b>', $text);
        $text = (string) preg_replace('/^([ \t]*)[-*+][ \t]+/m', '$1• ', $text);
        $text = (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<b>$1</b>', $text);
        $text = (string) preg_replace('/__(?=\S)(.+?)(?<=\S)__/s', '<b>$1</b>', $text);
        $text = (string) preg_replace('/(?<![*\w])\*(?=\S)([^*\n]+?)(?<=\S)\*(?![*\w])/', '<i>$1</i>', $text);
        $text = (string) preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/s', '<s>$1</s>', $text);

        return (string) preg_replace_callback(
            '/\x00(\d+)\x00/',
            static fn (array $m): string => $slots[(int) $m[1]] ?? '',
            $text,
        );
    }

    private static function escape(string $text, bool $attribute = false): string
    {
        $escaped = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);

        return $attribute ? str_replace('"', '&quot;', $escaped) : $escaped;
    }
}
