<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Message\AdvanceMediaJobCommand;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Hands a {@see MediaJob} to the background worker by dispatching an
 * {@see AdvanceMediaJobCommand} onto Messenger.
 *
 * This is the seam referenced in {@see MediaJobService}'s docblock: callers
 * (the chat/multitask media path in Sprint B, the advance handler re-arming
 * itself, and the reaper) all schedule the next advance step through here so
 * the "submit → poll → finalize" loop runs off the request in short,
 * non-blocking hops rather than pinning a worker for the whole render.
 */
final readonly class MediaJobDispatcher
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * Schedule the next advance step for a job.
     *
     * @param int $delaySeconds 0 = run as soon as a worker is free (used for the
     *                          initial dispatch and the running→finalizing hop);
     *                          >0 = wait before the next poll (the poll interval)
     *                          so we neither hammer the provider nor hold a worker
     */
    public function dispatch(MediaJob $job, int $delaySeconds = 0): void
    {
        $this->dispatchKey($job->getJobKey(), $delaySeconds);
    }

    public function dispatchKey(string $jobKey, int $delaySeconds = 0): void
    {
        $stamps = $delaySeconds > 0 ? [new DelayStamp($delaySeconds * 1000)] : [];
        $this->messageBus->dispatch(new AdvanceMediaJobCommand($jobKey), $stamps);
    }
}
