<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\AI\Service\AiFacade;
use Psr\Log\LoggerInterface;

/**
 * User-initiated cancellation of a background media job (Release 4.0, Sprint D).
 *
 * Drives an active job to `cancelled`, best-effort asks the provider to stop the
 * render so we stop being billed for output nobody is waiting for (async video
 * only — synchronous image/audio jobs have no operation handle), and syncs the
 * owning message (which also pushes the terminal state to the client). Lives in
 * its own service so the controller stays thin and the orchestration is unit-
 * testable without HTTP.
 */
final readonly class MediaJobCanceller
{
    public function __construct(
        private MediaJobService $jobService,
        private MediaJobMessageSync $messageSync,
        private AiFacade $aiFacade,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Cancel an active job. Returns false when the job is already terminal
     * (nothing to do), true when it was transitioned to `cancelled`.
     */
    public function cancel(MediaJob $job): bool
    {
        if ($job->isTerminal()) {
            return false;
        }

        $providerRef = $job->getProviderRef();
        if (MediaJob::TYPE_VIDEO === $job->getType() && null !== $providerRef && '' !== $providerRef) {
            // AiFacade::cancelVideoOperation is best-effort and never throws.
            $this->aiFacade->cancelVideoOperation(
                $providerRef,
                $job->getProvider(),
                $job->getUserId(),
                $job->getOptions(),
            );
        }

        $this->jobService->markCancelled($job);
        $this->messageSync->syncTerminalState($job);

        $this->logger->info('MediaJob cancelled by user', [
            'job_key' => $job->getJobKey(),
            'type' => $job->getType(),
            'provider' => $job->getProvider(),
        ]);

        return true;
    }
}
