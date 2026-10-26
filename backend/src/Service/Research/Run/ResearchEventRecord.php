<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/**
 * One stored event line: `{seq}\n{"event","data"}`.
 */
final class ResearchEventRecord
{
    /**
     * @return array{seq: int, event: string, data: array<string, mixed>}|null
     */
    public static function parse(string $line): ?array
    {
        $pos = strpos($line, "\n");
        if (false === $pos) {
            return null;
        }
        $seq = substr($line, 0, $pos);
        if (1 !== preg_match('/^[1-9][0-9]*$/', $seq)) {
            return null;
        }
        $body = json_decode(substr($line, $pos + 1), true);
        if (!is_array($body) || !is_string($body['event'] ?? null)) {
            return null;
        }
        $data = $body['data'] ?? [];

        return [
            'seq' => (int) $seq,
            'event' => $body['event'],
            'data' => is_array($data) ? $data : [],
        ];
    }
}
