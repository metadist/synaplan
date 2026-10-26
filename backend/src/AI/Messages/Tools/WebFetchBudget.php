<?php

declare(strict_types=1);

namespace App\AI\Messages\Tools;

/**
 * Pages Synaplan may still open while it answers one message.
 *
 * {@see GatewayToolLoop} creates one per message and hands it to every tool
 * round, so the count holds for a streamed answer too and never carries over
 * into the next request on a long-lived worker.
 */
final class WebFetchBudget
{
    private int $used = 0;

    public function __construct(
        private readonly int $max,
    ) {
    }

    public function claim(): bool
    {
        if ($this->used >= $this->max) {
            return false;
        }
        ++$this->used;

        return true;
    }
}
