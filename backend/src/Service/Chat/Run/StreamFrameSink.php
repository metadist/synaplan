<?php

declare(strict_types=1);

namespace App\Service\Chat\Run;

/**
 * One SSE frame, exactly as it would have been written to the client.
 */
interface StreamFrameSink
{
    public function write(string $bytes): void;
}
