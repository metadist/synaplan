<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Parallel;

/**
 * Starts a media node running concurrently and hands back a job to collect later.
 * Implementations must NOT block in {@see dispatch()} — that is what enables
 * overlap with inline text streaming.
 */
interface MediaNodeDispatcher
{
    public function dispatch(MediaNodeRequest $request): MediaNodeJob;
}
