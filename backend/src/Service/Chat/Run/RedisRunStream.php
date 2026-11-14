<?php

declare(strict_types=1);

namespace App\Service\Chat\Run;

/**
 * The run log in Redis. Ids are `0-{seq}`; `0-0` is not a frame.
 */
final class RedisRunStream implements RunStream
{
    public function __construct(
        private readonly StreamRedis $redis,
    ) {
    }

    public function append(string $runId, int $seq, string $kind, string $frame): void
    {
        $this->redis->appendFrame($runId, $seq, $kind, $frame);
    }

    public function after(string $runId, int $afterSeq): array
    {
        return $this->redis->framesAfter($runId, $afterSeq);
    }
}
