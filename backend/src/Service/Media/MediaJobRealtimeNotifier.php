<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Realtime\Channel\UserChannel;
use App\Realtime\Publisher\RealtimePublisherInterface;
use Psr\Log\LoggerInterface;

/**
 * Publishes a media job's current state to the owner's per-user Centrifugo
 * channel (`user:{id}`) as a `media_job.update` event (Release 4.0, Sprint C).
 *
 * This is the "push primary" half of completion delivery: the frontend patches
 * the matching message's banner the instant a render finishes/fails, instead of
 * waiting up to one 25s poll cycle. Realtime is a best-effort enhancement on top
 * of the persisted source of truth — the publisher already swallows transport
 * errors, and this notifier additionally never throws so a flaky gateway can
 * never break the terminal-state sync (the DB write + poll fallback still win).
 */
final readonly class MediaJobRealtimeNotifier
{
    public const EVENT = 'media_job.update';

    public function __construct(
        private RealtimePublisherInterface $publisher,
        private MediaJobService $mediaJobService,
        private LoggerInterface $logger,
    ) {
    }

    public function publishUpdate(MediaJob $job): void
    {
        $userId = $job->getUserId();
        if ($userId <= 0) {
            // No owner channel to address (e.g. anonymous/system job).
            return;
        }

        try {
            $status = $this->mediaJobService->toStatusArray($job);

            $this->publisher->publish(
                new UserChannel($userId),
                self::EVENT,
                [
                    'job_id' => $job->getJobKey(),
                    'message_id' => $job->getMessageId(),
                    'chat_id' => $job->getChatId(),
                    'node_id' => $job->getNodeId(),
                    'type' => $job->getType(),
                    'state' => $status['state'],
                    'percent' => $status['percent'],
                    'error' => $status['error'],
                    'file' => $status['file'],
                ],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('MediaJobRealtimeNotifier: publish failed (ignored)', [
                'job_key' => $job->getJobKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
