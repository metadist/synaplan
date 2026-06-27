<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Repository\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Keeps the persisted OUT message in sync with a terminal {@see MediaJob}.
 *
 * Two responsibilities:
 *   1. Update the `media_job` meta blob so a reload of the chat shows the same
 *      state Redis already knows (without this, a failed/completed job would
 *      reload as a perpetually "running" banner because the initial detach
 *      meta is the only record on the DB).
 *   2. On COMPLETED, register the generated file as a {@see \App\Entity\File}
 *      and attach it to the message — this is the single channel history
 *      endpoints serialize (`getFiles()`), so without it a reload shows an
 *      empty bubble even though the file is on disk and the job is `done`.
 */
final readonly class MediaJobMessageSync
{
    public function __construct(
        private MessageRepository $messageRepository,
        private MediaJobService $mediaJobService,
        private GeneratedFileRegistrar $fileRegistrar,
        private MediaJobRealtimeNotifier $realtimeNotifier,
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
            $this->attachGeneratedFile($job, $message);
        }

        $this->em->flush();

        $this->logger->info('MediaJobMessageSync: updated message meta for terminal job', [
            'job_key' => $job->getJobKey(),
            'message_id' => $messageId,
            'state' => $status['state'],
        ]);

        // Push the terminal state to the owner so the banner resolves instantly
        // (best-effort; the persisted state above + the client poll are the
        // fallback if realtime is disabled/unreachable).
        $this->realtimeNotifier->publishUpdate($job);
    }

    /**
     * Register the file the worker wrote during finalize as a `File` entity and
     * link it to the message. Without this, a reload would still show an empty
     * bubble: the live poll picks up `result.file.url` and adds the part on the
     * client, but that part is lost on refresh because nothing connects it to
     * the message row in the DB.
     */
    private function attachGeneratedFile(MediaJob $job, \App\Entity\Message $message): void
    {
        $result = $job->getResult();
        $url = is_array($result['file'] ?? null) ? (string) ($result['file']['url'] ?? '') : '';
        if ('' === $url) {
            return;
        }

        // The worker stores the URL as `/api/v1/files/uploads/<relative_path>`;
        // strip the route prefix to get the path inside the upload directory.
        $prefix = '/api/v1/files/uploads/';
        if (!str_starts_with($url, $prefix)) {
            return;
        }
        $relativePath = substr($url, strlen($prefix));

        // Idempotency: if the message already has a generated file at this
        // path, we're done — avoids creating a duplicate File row on a retried
        // sync (e.g. the reaper firing on a job the worker already completed).
        foreach ($message->getFiles() as $existing) {
            if ($existing->getFilePath() === $relativePath) {
                return;
            }
        }

        $file = $this->fileRegistrar->register($job->getUserId(), $relativePath, $job->getType());
        if (null === $file) {
            $this->logger->warning('MediaJobMessageSync: failed to register generated file', [
                'job_key' => $job->getJobKey(),
                'message_id' => $message->getId(),
                'path' => $relativePath,
            ]);

            return;
        }

        $message->addFile($file);

        // Mirror the synchronous media path (StreamController) by also setting
        // the legacy file columns. The history formatter exposes generated
        // media through the `file` field (BFILE/BFILEPATH/BFILETYPE), and the
        // frontend mapper builds the video/image/audio part from that field —
        // NOT from the `files[]` relation (which it only renders for audio).
        // Setting these makes a page reload show the video through the exact
        // same proven path as a normally-generated clip.
        $message->setFile(1);
        $message->setFilePath($relativePath);
        $message->setFileType($job->getType());
    }
}
