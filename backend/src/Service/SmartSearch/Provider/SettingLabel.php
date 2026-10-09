<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Provider;

/**
 * A readable title for a setting row. The config schema has no label per
 * field, but every description opens with one ("Users & groups: …",
 * "Deep memory master switch. …", "Hours a pending approval waits (1–720)").
 * The key stays visible in the breadcrumb for admins who search by it.
 */
final class SettingLabel
{
    private const MAX_LENGTH = 64;
    private const MIN_LENGTH = 3;
    private const HEAD_ENDS = [': ', ' — ', ' – ', '. ', ' ('];

    public static function of(string $key, string $description): string
    {
        $text = trim($description);
        if ('' === $text) {
            return self::humanize($key);
        }

        $end = mb_strlen($text);
        foreach (self::HEAD_ENDS as $separator) {
            $position = mb_strpos($text, $separator);
            if (false !== $position && $position < $end) {
                $end = $position;
            }
        }
        $head = rtrim(mb_substr($text, 0, $end), ' .:');

        if (mb_strlen($head) < self::MIN_LENGTH) {
            return self::humanize($key);
        }
        if (mb_strlen($head) <= self::MAX_LENGTH) {
            return $head;
        }

        $cut = mb_substr($head, 0, self::MAX_LENGTH);
        $space = mb_strrpos($cut, ' ');

        return rtrim(false === $space ? $cut : mb_substr($cut, 0, $space), ' ,;').'…';
    }

    /** "MIN_EXTRACTION_SCORE" → "Min extraction score". */
    private static function humanize(string $key): string
    {
        $words = strtolower(str_replace('_', ' ', preg_replace('/^FEATURE_/', '', $key) ?? $key));

        return ucfirst(trim($words));
    }
}
