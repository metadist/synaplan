<?php

declare(strict_types=1);

namespace App\AI\Service;

/**
 * Strips invalid UTF-8 and control bytes from a chat payload before it
 * reaches a provider. Vision parts are nested arrays and are walked too.
 */
final class Utf8MessageSanitizer
{
    /**
     * @param list<mixed> $messages
     *
     * @return list<mixed>
     */
    public static function apply(array $messages): array
    {
        return array_map(static function (mixed $message): mixed {
            if (!is_array($message)) {
                return $message;
            }

            foreach ($message as $key => $value) {
                if (is_string($value)) {
                    $message[$key] = self::clean($value);
                } elseif (is_array($value)) {
                    $message[$key] = self::nested($value);
                }
            }

            return $message;
        }, $messages);
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private static function nested(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_string($item)) {
                $value[$key] = self::clean($item);
            } elseif (is_array($item)) {
                $value[$key] = self::nested($item);
            }
        }

        return $value;
    }

    private static function clean(string $value): string
    {
        $encoded = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $encoded);

        return is_string($cleaned) ? $cleaned : $encoded;
    }
}
