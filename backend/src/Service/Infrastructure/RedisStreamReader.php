<?php

declare(strict_types=1);

namespace App\Service\Infrastructure;

/**
 * Turns an XREAD reply into the entry list a stream log already understands.
 */
final class RedisStreamReader
{
    /**
     * @return list<array<mixed>>
     */
    public static function entries(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $entries = null;
        foreach ($raw as $value) {
            $entries = $value;
            break;
        }
        if (!is_array($entries)) {
            return [];
        }
        $rows = [];
        foreach ($entries as $entry) {
            if (is_array($entry)) {
                $rows[] = $entry;
            }
        }

        return $rows;
    }
}
