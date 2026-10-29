<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ChatRepository;
use App\Repository\MessageMetaRepository;
use App\Repository\SearchResultRepository;
use App\Repository\UserRepository;
use App\Service\Chat\Stream\StreamMessageMeta;
use App\Service\File\FileStorageService;
use App\Service\Media\GeneratedFileRegistrar;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Puts a finished web research run into its chat: the reader's report as the
 * assistant's message with the markdown file attached, or the sentence why
 * the run ended without one. The sources the report cites are stored under
 * the question, where the chat history shows them beneath the reply and
 * turns the citation numbers into links.
 *
 * A run the chat started on its own already has a reply — the one stamped
 * with the run id — and the report replaces that reply's text, so the person
 * reads the answer in the bubble that showed the research. A run started
 * by Look deeper has none, so its report becomes a new reply carrying the
 * same stamp.
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
        private ResearchReplyText $replies,
        private LoggerInterface $logger,
        private MessageMetaRepository $metas,
        private SearchResultRepository $sources,
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
            [$message, $file] = $this->post($state, $chat);
            $this->store->markDelivered($state->ref->id);
            $this->attachReport($state, $message, $file);
        } catch (\Throwable $e) {
            $this->logger->warning('Research report was not posted to the chat', [
                'run_id' => $state->ref->id,
                'chat_id' => $chatId,
                'exception' => $e,
            ]);
        }
    }

    /**
     * @return array{0: Message, 1: ?string} the reply and the text of the file to attach
     */
    private function post(ResearchRunState $state, Chat $chat): array
    {
        $locale = $this->locale($state->ref->userId);
        $message = $this->metas->findMessageInChatByMeta((int) $chat->getId(), StreamMessageMeta::RESEARCH_RUN, $state->ref->id)
            ?? $this->newReply($state, $chat, $locale);
        $reply = $this->replies->compose($state, $locale);
        $message
            ->setText($reply['text'])
            ->setStatus('complete')
            ->setMeta(StreamMessageMeta::RESEARCH_RUN, $state->ref->id);
        if ([] !== $reply['sources'] && $this->sources->saveForReply($message, $reply['sources'], $state->ref->question)) {
            $message->setMeta('web_search_results_count', (string) count($reply['sources']));
        }
        $this->em->persist($message);
        $chat->updateTimestamp();
        $this->em->flush();

        return [$message, $reply['file']];
    }

    private function newReply(ResearchRunState $state, Chat $chat, string $locale): Message
    {
        $now = time();

        return (new Message())
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
            ->setDirection('OUT');
    }

    private function attachReport(ResearchRunState $state, Message $message, ?string $report): void
    {
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
        if ('' !== $stored['path']) {
            $state->reportFile = [
                'path' => '/api/v1/files/uploads/'.$stored['path'],
                'type' => 'text/markdown',
                'local_path' => $stored['path'],
            ];
        }
    }

    private function locale(int $userId): string
    {
        $user = $this->users->find($userId);

        return $user instanceof User ? $user->getLocale() : 'en';
    }
}
