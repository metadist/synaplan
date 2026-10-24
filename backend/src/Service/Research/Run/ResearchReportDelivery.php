<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Entity\Chat;
use App\Entity\File;
use App\Entity\Message;
use App\Repository\ChatRepository;
use App\Service\File\FileStorageService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Puts a finished web research report into its chat.
 *
 * An API run has no chat, so this does nothing for it. The report file is a
 * markdown attachment. A failure here does not undo the finished run.
 */
final readonly class ResearchReportDelivery implements ResearchReportPoster
{
    public function __construct(
        private ChatRepository $chats,
        private EntityManagerInterface $em,
        private FileStorageService $storage,
        private ResearchStateStore $store,
        private LoggerInterface $logger,
    ) {
    }

    public function deliver(ResearchRunState $state): void
    {
        $chatId = $state->ref->chatId;
        if (null === $chatId || $this->store->wasDelivered($state->ref->id)) {
            return;
        }
        $chat = $this->chats->find($chatId);
        if (!$chat instanceof Chat || $chat->getUserId() !== $state->ref->userId) {
            $this->store->markDelivered($state->ref->id);

            return;
        }

        try {
            $fileId = $this->storeReport($state);
            $message = (new Message())
                ->setUserId($state->ref->userId)
                ->setChat($chat)
                ->setTrackingId(time())
                ->setProviderIndex('RESEARCH')
                ->setUnixTimestamp(time())
                ->setDateTime(date('YmdHis'))
                ->setMessageType('API')
                ->setFile(null !== $fileId ? 1 : 0)
                ->setTopic('CHAT')
                ->setLanguage('en')
                ->setText($this->text($state))
                ->setDirection('OUT')
                ->setStatus('complete');
            $this->em->persist($message);
            $chat->updateTimestamp();
            $this->em->flush();
            $this->store->markDelivered($state->ref->id);
        } catch (\Throwable $e) {
            $this->logger->warning('Research report was not posted to the chat', [
                'run_id' => $state->ref->id,
                'chat_id' => $chatId,
                'exception' => $e,
            ]);
        }
    }

    private function text(ResearchRunState $state): string
    {
        $report = $state->ledger->report();
        if (null !== $report && '' !== $report) {
            return $report;
        }

        return $state->outcome?->text() ?? 'The research finished without a report.';
    }

    private function storeReport(ResearchRunState $state): ?int
    {
        $report = $state->ledger->report();
        if (null === $report || '' === $report) {
            return null;
        }
        $name = 'research-'.$state->ref->id.'.md';
        $stored = $this->storage->storeRawContent($report, $state->ref->userId, $name, 'text/markdown');
        if (true !== $stored['success'] || '' === $stored['path']) {
            $this->logger->warning('Research report file was not saved', [
                'run_id' => $state->ref->id,
                'error' => $stored['error'] ?? null,
            ]);

            return null;
        }
        $file = new File();
        $file->setUserId($state->ref->userId);
        $file->setFilePath($stored['path']);
        $file->setFileName($name);
        $file->setFileSize($stored['size']);
        $file->setFileMime('text/markdown');
        $file->setFileType('md');
        $file->setStatus('complete');
        $file->setSource('chat_attachment');
        $file->setIncoming(false);
        $file->setEphemeral(false);
        $this->em->persist($file);
        $this->em->flush();

        return (int) $file->getId();
    }
}
