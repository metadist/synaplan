<?php

declare(strict_types=1);

namespace App\Tests\Characterization\Support;

/**
 * Stable shape for a tool result. Long texts keep only their length and ends,
 * so the clamp (12,000 characters plus an ellipsis) is visible without a
 * 12 KB snapshot line.
 */
final class ToolLoopText
{
    /**
     * @return string|array{chars: int, head: string, tail: string}
     */
    public static function pack(string $text): string|array
    {
        if (mb_strlen($text) <= 180) {
            return $text;
        }

        return [
            'chars' => mb_strlen($text),
            'head' => mb_substr($text, 0, 8),
            'tail' => mb_substr($text, -8),
        ];
    }

    /**
     * Drop ids the loops mint with random bytes (`q` plus 24 hex digits).
     *
     * @param array<string, mixed> $value
     *
     * @return array<string, mixed>
     */
    public static function stripRandom(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_string($item) && 1 === preg_match('/^q[0-9a-f]{24}$/', $item)) {
                $value[$key] = 'q…';
                continue;
            }
            if (is_array($item)) {
                /* @var array<string, mixed> $item */
                $value[$key] = self::stripRandom($item);
            }
        }

        return $value;
    }
}
