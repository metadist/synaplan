<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Repository\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Keeps the persisted OUT message in sync with a terminal {@see MediaJob}.
 *
 * Without this, reload would still show `state: running` from the initial
 * detach meta even though Redis already knows the job failed or finished.
 */
final readonly class MediaJobMessageSync
{
    public function __construct(
        private MessageRepository $messageRepository,
        private MediaJobService $mediaJobService,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function syncTerminalState(MediaJob $job): void
    {
        if (!$job->isTerminal()) {
            return;
        }

        $messageId = $job->getMessageId();
        if (null === $messageId) {
            return;
        }

        $message = $this->messageRepository->find($messageId);
        if (null === $message) {
            return;
        }

        $status = $this->mediaJobService->toStatusArray($job);
        $mediaJobMeta = [
            'job_id' => $job->getJobKey(),
            'type' => $job->getType(),
            'state' => $status['state'],
        ];
        if (null !== $job->getError() && '' !== $job->getError()) {
            $mediaJobMeta['error'] = $job->getError();
        }

        $message->setMeta(
            'media_job',
            (string) json_encode($mediaJobMeta, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)
        );

        if (MediaJob::STATUS_FAILED === $job->getStatus() || MediaJob::STATUS_TIMED_OUT === $job->getStatus()) {
            $errorText = $job->getError();
            if (null !== $errorText && '' !== trim($errorText)) {
                $message->setText($errorText);
            }
        } elseif (MediaJob::STATUS_COMPLETED === $job->getStatus()) {
            $message->setText('');
        }

        $this->em->flush();

        $this->logger->info('MediaJobMessageSync: updated message meta for terminal job', [
            'job_key' => $job->getJobKey(),
            'message_id' => $messageId,
            'state' => $status['state'],
        ]);
    }
}
