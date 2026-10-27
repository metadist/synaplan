<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Tell one cloud that its watch index changed. Queued after the write is
 * committed, so the request does not wait on the cloud.
 *
 * Queued in: async_index
 * Handled by: NotifyWatchesChangedHandler
 */
final readonly class NotifyWatchesChanged
{
    public function __construct(public string $instanceId)
    {
    }
}
