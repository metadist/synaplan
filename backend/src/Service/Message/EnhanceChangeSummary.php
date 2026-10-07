<?php

declare(strict_types=1);

namespace App\Service\Message;

/**
 * Plain-language code for what an Enhance rewrite actually changed.
 *
 * The frontend translates the code. The model text stays in the composer.
 */
final class EnhanceChangeSummary
{
    public const UNCHANGED = 'unchanged';

    public const CAPITALIZED = 'capitalized';

    public const REWRITTEN = 'rewritten';

    public static function code(string $original, string $enhanced): string
    {
        $original = trim($original);
        $enhanced = trim($enhanced);
        if ($original === $enhanced) {
            return self::UNCHANGED;
        }

        if (self::words($original) === self::words($enhanced)) {
            return self::CAPITALIZED;
        }

        return self::REWRITTEN;
    }

    private static function words(string $text): string
    {
        $collapsed = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text));

        return trim((string) $collapsed);
    }
}
