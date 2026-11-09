<?php

declare(strict_types=1);

namespace App\Service\Chat\Run;

/**
 * One entry of `chatrun:{runId}:stream`. `frame` is the exact client bytes,
 * without the SSE id line. The id is `0-{seq}`.
 */
final readonly class RunFrame
{
    public function __construct(
        public int $seq,
        public string $kind,
        public string $frame,
    ) {
    }

    public function id(): string
    {
        return '0-'.$this->seq;
    }
}
