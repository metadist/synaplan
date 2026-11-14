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

    /**
     * @param \Closure(string): bool|null $pumpAlive true while the run is still owned by a live pump
     */
    public function __construct(
        private readonly RunStream $stream,
        private readonly ?\Closure $pumpAlive = null,
    ) {
    }

    /**
     * Silence ends the reader only when the pump is gone. A live pump may
     * spend a long time before the next frame.
     */
    public static function quietIsFatal(float $quietFor, int $idleSeconds, bool $pumpAlive): bool
    {
        return $quietFor >= $idleSeconds && !$pumpAlive;
    }

    public function copy(string $runId, int $idleSeconds = 60): void
    {
        SseOutput::begin(false);
        set_time_limit(0);
        // A reader has nothing to finish after the browser leaves. The pump
        // keeps generating for whoever attaches next.
        ignore_user_abort(false);
        $this->follow($runId, $idleSeconds, function (string $bytes): void {
            echo $bytes;
            $this->flush();
        }, static fn (): bool => connection_aborted() > 0);
    }

    /**
     * @param callable(string): void  $write
     * @param (callable(): bool)|null $disconnected
     */
    public function follow(string $runId, int $idleSeconds, callable $write, ?callable $disconnected = null): void
    {
        $after = 0;
        $started = microtime(true);
        $lastFrameAt = $started;
        $lastKeepalive = $started;
        $sawFrame = false;
        while (true) {
            if (null !== $disconnected && $disconnected()) {
                return;
            }
            $frames = $this->stream->after($runId, $after);
            $now = microtime(true);
            if ([] === $frames) {
                $quietFor = $now - ($sawFrame ? $lastFrameAt : $started);
                if (self::quietIsFatal($quietFor, $idleSeconds, $this->pumpIsAlive($runId))) {
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

    private function pumpIsAlive(string $runId): bool
    {
        if (null === $this->pumpAlive) {
            return false;
        }

        return ($this->pumpAlive)($runId);
    }

    private function flush(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}
