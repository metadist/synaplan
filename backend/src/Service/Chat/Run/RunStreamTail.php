<?php

declare(strict_types=1);

namespace App\Service\Chat\Run;

use App\Service\Realtime\SseOutput;

/**
 * Copies a run's frames to the PHP response that is still holding the client.
 *
 * The pump writes the frames. This only waits, echoes, and sends a
 * keep-alive while the run is quiet. A terminal entry ends the response.
 */
final class RunStreamTail
{
    private const POLL_MICROSECONDS = 50000;

    private const KEEPALIVE_SECONDS = 15;

    public function __construct(
        private readonly RunStream $stream,
    ) {
    }

    public function copy(string $runId, int $idleSeconds = 60): void
    {
        SseOutput::begin(false);
        set_time_limit(0);
        ignore_user_abort(true);
        $this->follow($runId, $idleSeconds, function (string $bytes): void {
            echo $bytes;
            $this->flush();
        });
    }

    /**
     * @param callable(string): void $write
     */
    public function follow(string $runId, int $idleSeconds, callable $write): void
    {
        $after = 0;
        $started = microtime(true);
        $lastFrameAt = $started;
        $lastKeepalive = $started;
        $sawFrame = false;
        while (true) {
            $frames = $this->stream->after($runId, $after);
            $now = microtime(true);
            if ([] === $frames) {
                $quietFor = $now - ($sawFrame ? $lastFrameAt : $started);
                if ($quietFor >= $idleSeconds) {
                    break;
                }
                if (($now - $lastKeepalive) >= self::KEEPALIVE_SECONDS) {
                    $write(": keep-alive\n\n");
                    $lastKeepalive = $now;
                }
                usleep(self::POLL_MICROSECONDS);

                continue;
            }

            $sawFrame = true;
            $lastFrameAt = $now;
            foreach ($frames as $frame) {
                $write($frame->frame);
                $after = $frame->seq;
                if ('terminal' === $frame->kind || 'gap' === $frame->kind) {
                    return;
                }
            }
        }

        $write($sawFrame ? PumpFrames::LOST_PUMP : PumpFrames::DID_NOT_START);
    }

    private function flush(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}
