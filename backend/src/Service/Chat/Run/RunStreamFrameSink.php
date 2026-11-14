<?php

declare(strict_types=1);

namespace App\Service\Chat\Run;

/**
 * Turns the bytes {@see \App\Service\Realtime\SseWriter} would echo into
 * run-stream entries. A complete or error event is the terminal entry.
 */
final class RunStreamFrameSink implements StreamFrameSink
{
    private int $seq = 0;

    public function __construct(
        private readonly RunStream $stream,
        private readonly string $runId,
    ) {
    }

    public function write(string $bytes): void
    {
        ++$this->seq;
        $this->stream->append($this->runId, $this->seq, self::kind($bytes), $bytes);
    }

    public static function kind(string $bytes): string
    {
        $json = json_decode(trim(substr($bytes, 5)), true);
        if (!is_array($json)) {
            return 'frame';
        }
        $status = $json['status'] ?? null;

        return 'complete' === $status || 'error' === $status ? 'terminal' : 'frame';
    }
}
