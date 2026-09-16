<?php

declare(strict_types=1);

namespace App\Service\Stt;

/**
 * Writes Server-Sent Events for the external STT stream.
 */
final class SttSseWriter
{
    /**
     * @param array<string, mixed> $data
     */
    public function write(string $event, array $data): void
    {
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }
}
