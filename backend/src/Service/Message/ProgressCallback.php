<?php

declare(strict_types=1);

namespace App\Service\Message;

/**
 * The status events a chat turn reports while it works (searching files,
 * checking memories, generating …). The web stream forwards each one as an
 * SSE status event; channels without live progress pass no callback.
 */
final class ProgressCallback
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function notify(?callable $callback, string $status, string $message, array $metadata = []): void
    {
        if ($callback) {
            $callback([
                'status' => $status,
                'message' => $message,
                'metadata' => $metadata,
                'timestamp' => microtime(true),
            ]);
        }
    }
}
