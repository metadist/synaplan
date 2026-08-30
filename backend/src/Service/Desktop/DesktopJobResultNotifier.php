<?php

declare(strict_types=1);

namespace App\Service\Desktop;

use App\Entity\Chat;
use App\Entity\DesktopJob;
use App\Entity\Message;
use App\Repository\ChatRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Posts the "done" note back into the chat that queued a desktop job, so a
 * result computed on the user's laptop re-enters the account like any other
 * channel's message.
 *
 * Provenance is stamped ({@see DesktopJobContract::RESULT_SOURCE}) because this
 * text originates on an untrusted device — it is content, never an instruction
 * the pipeline should act on.
 */
final readonly class DesktopJobResultNotifier
{
    public function __construct(
        private ChatRepository $chatRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Post a completion (or failure) note into the job's chat, if it has one.
     * Never throws — a failure to notify must not fail the device's report.
     */
    public function notify(DesktopJob $job): void
    {
        $chatId = $job->getChatId();
        if (null === $chatId) {
            return;
        }

        try {
            $chat = $this->chatRepository->find($chatId);
            if (!$chat instanceof Chat || $chat->getUserId() !== $job->getOwnerId()) {
                return;
            }

            $message = (new Message())
                ->setUserId($job->getOwnerId())
                ->setChat($chat)
                ->setTrackingId(time())
                ->setProviderIndex('DESKTOP')
                ->setUnixTimestamp(time())
                ->setDateTime(date('YmdHis'))
                ->setMessageType('API')
                ->setFile(0)
                ->setTopic('CHAT')
                ->setLanguage('en')
                ->setText($this->buildText($job))
                ->setDirection('OUT')
                ->setStatus('complete');

            $this->em->persist($message);
            $chat->updateTimestamp();
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to post desktop job completion note', [
                'job_id' => $job->getId(),
                'chat_id' => $chatId,
                'exception' => $e,
            ]);
        }
    }

    private function buildText(DesktopJob $job): string
    {
        $skill = (string) ($job->getInput()['skill'] ?? 'skill');

        if (DesktopJob::STATUS_SUCCEEDED !== $job->getStatus()) {
            $code = $job->getErrorCode() ?? DesktopJobContract::ERROR_LOCAL_ERROR;

            return \sprintf('The "%s" task on your computer did not complete (%s).', $skill, $code);
        }

        $result = $job->getResult() ?? [];
        $lines = [\sprintf('The "%s" task finished on your computer.', $skill)];

        $summary = $result['summary'] ?? null;
        if (\is_string($summary) && '' !== trim($summary)) {
            $lines[] = trim($summary);
        }

        $fileIds = [];
        if (isset($result['fileIds']) && \is_array($result['fileIds'])) {
            foreach ($result['fileIds'] as $fileId) {
                if (is_numeric($fileId)) {
                    $fileIds[] = (int) $fileId;
                }
            }
        }
        if ([] !== $fileIds) {
            $lines[] = 'Files: '.implode(', ', array_map(static fn (int $id): string => '#'.$id, $fileIds));
        }

        return implode("\n", $lines);
    }
}
