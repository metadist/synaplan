<?php

declare(strict_types=1);

namespace App\Service\Usage;

/**
 * One statistics activity row, including the Demo fields a refusal stores:
 * the error code, the install, the alias and the path.
 */
final class UsageActivityRow
{
    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function from(array $row, string $channel, ?float $durationSeconds, ?int $chatId): array
    {
        $metadata = self::decode(is_string($row['metadata'] ?? null) ? $row['metadata'] : null);
        $http = is_numeric($metadata['http'] ?? null) ? (int) $metadata['http'] : null;
        if (null !== $http && ($http < 100 || $http > 599)) {
            $http = null;
        }

        return [
            'timestamp' => (int) ($row['timestamp'] ?? 0),
            'datetime' => date('Y-m-d H:i:s', (int) ($row['timestamp'] ?? 0)),
            'action' => $row['action'] ?? '',
            'source' => is_string($row['source'] ?? null) && '' !== $row['source'] ? $row['source'] : 'WEB',
            'channel' => $channel,
            'model' => $row['model'] ?? '',
            'tokens' => (int) ($row['tokens'] ?? 0),
            'prompt_tokens' => (int) ($row['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($row['completion_tokens'] ?? 0),
            'cached_tokens' => (int) ($row['cached_tokens'] ?? 0),
            'cache_creation_tokens' => (int) ($row['cache_creation_tokens'] ?? 0),
            'estimated' => (bool) ($row['estimated'] ?? false),
            'cost' => (float) ($row['cost'] ?? 0),
            'latency' => (float) ($row['latency'] ?? 0),
            'status' => is_string($row['status'] ?? null) && '' !== $row['status'] ? $row['status'] : 'success',
            'error' => is_string($row['error'] ?? null) ? $row['error'] : '',
            'install_id' => self::text($metadata['install_id'] ?? null),
            'alias' => self::text($metadata['alias'] ?? null),
            'path' => self::text($metadata['path'] ?? null),
            'http' => $http,
            'duration_seconds' => $durationSeconds,
            'chat_id' => $chatId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(?string $metadata): array
    {
        if (null === $metadata || '' === $metadata) {
            return [];
        }
        $decoded = json_decode($metadata, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return '' !== $value ? $value : null;
    }
}
