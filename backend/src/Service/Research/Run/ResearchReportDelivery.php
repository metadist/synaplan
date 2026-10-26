<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ChatRepository;
use App\Repository\UserRepository;
use App\Service\File\FileStorageService;
use App\Service\Media\GeneratedFileRegistrar;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Puts a finished web research run into its chat: the report as the
 * assistant's message with the markdown file attached, or the sentence why
 * the run ended without one. A run that did not complete says so above the
 * report, so a partial report never reads as a finished one.
 *
 * An API run has no chat, so this does nothing for it. Posting happens once
 * per run; a failure is logged and never undoes the finished run.
 */
final readonly class ResearchReportDelivery implements ResearchReportPoster
{
    public function __construct(
        private ChatRepository $chats,
        private UserRepository $users,
        private EntityManagerInterface $em,
        private FileStorageService $storage,
        private GeneratedFileRegistrar $files,
        private ResearchStateStore $store,
        private ResearchOutcomeText $outcomes,
        private LoggerInterface $logger,
    ) {
    }

    public function deliver(ResearchRunState $state): void
    {
        $chatId = $state->ref->chatId;
        if (null === $chatId || !$state->status->isTerminal()) {
            return;
        }

        try {
            if ($this->store->wasDelivered($state->ref->id)) {
                return;
            }
            $chat = $this->chats->find($chatId);
            if (!$chat instanceof Chat || $chat->getUserId() !== $state->ref->userId) {
                $this->store->markDelivered($state->ref->id);

                return;
            }
            $message = $this->post($state, $chat);
            $this->store->markDelivered($state->ref->id);
            $this->attachReport($state, $message);
        } catch (\Throwable $e) {
            $this->logger->warning('Research report was not posted to the chat', [
                'run_id' => $state->ref->id,
                'chat_id' => $chatId,
                'exception' => $e,
            ]);
        }
    }

    private function post(ResearchRunState $state, Chat $chat): Message
    {
        $locale = $this->locale($state->ref->userId);
        $now = time();
        $message = (new Message())
            ->setUserId($state->ref->userId)
            ->setChat($chat)
            ->setTrackingId($now)
            ->setProviderIndex('RESEARCH')
            ->setUnixTimestamp($now)
            ->setDateTime(date('YmdHis', $now))
            ->setMessageType('API')
            ->setFile(0)
            ->setTopic('CHAT')
            ->setLanguage($locale)
            ->setText($this->text($state, $locale))
            ->setDirection('OUT')
            ->setStatus('complete');
        $this->em->persist($message);
        $chat->updateTimestamp();
        $this->em->flush();

        return $message;
    }

    private function text(ResearchRunState $state, string $locale): string
    {
        $report = trim($state->ledger->report() ?? '');
        $outcome = $state->outcome;
        if ('' === $report) {
            return $this->outcomes->sentence($outcome, $locale);
        }
        if (ResearchOutcome::COMPLETE === $outcome?->code) {
            return $report;
        }

        return '> '.$this->outcomes->sentence($outcome, $locale)."\n\n".$report;
    }

    private function attachReport(ResearchRunState $state, Message $message): void
    {
        $report = $state->ledger->report();
        if (null === $report || '' === trim($report)) {
            return;
        }
        $stored = $this->storage->storeRawContent($report, $state->ref->userId, 'research-'.$state->ref->id.'.md', 'text/markdown');
        $file = true === $stored['success']
            ? $this->files->register($state->ref->userId, $stored['path'], '', $message->getId())
            : null;
        if (null === $file) {
            $this->logger->warning('Research report file was not saved; the report stays in the chat message', [
                'run_id' => $state->ref->id,
                'error' => $stored['error'],
            ]);

            return;
        }
        $message->addFile($file);
        $this->em->flush();
    }

    private function locale(int $userId): string
    {
        $user = $this->users->find($userId);

        return $user instanceof User ? $user->getLocale() : 'en';
    }
}
