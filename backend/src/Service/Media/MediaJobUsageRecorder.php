<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Repository\UserRepository;
use App\Service\RateLimitService;
use App\Service\Usage\RecordedUsage;
use Psr\Log\LoggerInterface;

/**
 * Records billable usage for a detached media job at its terminal state
 * (Release 4.0, Sprint E — issue #1146).
 *
 * The synchronous chat path bills the moment the provider charges us; a
 * detached job never ran that code, so without this an async render would
 * bypass quota/billing entirely. We bill ONLY on `completed` (a render the
 * provider actually produced) — failed / timed-out / cancelled jobs are never
 * billed, which automatically honours the provider refund rules (e.g.
 * Higgsfield refunds failed/nsfw and cancel-while-queued). Recording is
 * idempotent (a `_usage_recorded` flag on the job) so a re-sync can never
 * double-charge, and never throws so a billing hiccup can't break completion.
 */
final readonly class MediaJobUsageRecorder
{
    public function __construct(
        private RateLimitService $rateLimitService,
        private UserRepository $userRepository,
        private MediaJobService $jobService,
        private LoggerInterface $logger,
    ) {
    }

    public function record(MediaJob $job): ?RecordedUsage
    {
        if (MediaJob::STATUS_COMPLETED !== $job->getStatus()) {
            return null;
        }

        $options = $job->getOptions();
        if (!empty($options['_usage_recorded'])) {
            return null;
        }

        $userId = $job->getUserId();
        if ($userId <= 0) {
            return null;
        }

        $user = $this->userRepository->find($userId);
        if (null === $user) {
            return null;
        }

        $action = match ($job->getType()) {
            MediaJob::TYPE_VIDEO => 'VIDEOS',
            MediaJob::TYPE_AUDIO => 'AUDIOS',
            default => 'IMAGES',
        };
        $mediaUsage = is_array($options['media_usage'] ?? null) ? $options['media_usage'] : [];

        try {
            $recorded = $this->rateLimitService->recordUsage($user, $action, [
                'provider' => $job->getProvider(),
                'model' => $job->getModel() ?? 'unknown',
                'model_id' => $job->getModelId(),
                'media_usage' => $mediaUsage,
            ]);
        } catch (\Throwable $e) {
            // Never let a billing-record hiccup break the terminal sync; leave
            // the flag unset so a later retry can still bill.
            $this->logger->error('MediaJobUsageRecorder: failed to record usage', [
                'job_key' => $job->getJobKey(),
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $options['_usage_recorded'] = true;
        // Stash the charged cost so a later (re-)sync of the message can still
        // write the taximeter usage meta even though billing already happened
        // (e.g. job billed while bound to the IN message, meta written after
        // the rebind to the OUT message).
        $options['_usage_charged_cost'] = $recorded->chargedCost;
        $job->setOptions($options);
        $this->jobService->save($job);

        return $recorded;
    }
}
