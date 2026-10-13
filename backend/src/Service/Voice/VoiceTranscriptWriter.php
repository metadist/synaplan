<?php

declare(strict_types=1);

namespace App\Service\Voice;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\Model;
use App\Entity\User;
use App\Entity\VoiceSession;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\Chat\ChatTitleService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Writes closed transcript turns into the chat. Incognito writes nothing.
 * A repeated turn (same speaker, same text) is stored once, and a turn that
 * only extends the speaker's last message of the same call updates it.
 */
final readonly class VoiceTranscriptWriter
{
    public function __construct(
        private EntityManagerInterface $em,
        private MessageRepository $messages,
        private UserRepository $users,
        private VoiceLiveUsageService $usage,
        private VoiceTurnSegmenter $segmenter,
        private ChatTitleService $titles,
        private LoggerInterface $logger,
        private VoiceReplyStamp $stamp = new VoiceReplyStamp(),
    ) {
    }

    /**
     * @param list<array{role?: mixed, text?: mixed, startMs?: mixed, endMs?: mixed}> $chunks
     */
    public function record(VoiceSession $session, array $chunks, ?VoiceLiveMeter $meter = null): ?Message
    {
        if ($this->usage->isIncognito($session) || null === $session->getChatId()) {
            return null;
        }
        if ($meter instanceof VoiceLiveMeter) {
            $this->usage->noteMeter($session, $meter);
        }

        $chat = $this->em->find(Chat::class, $session->getChatId());
        $user = $this->users->find($session->getUserId());
        if (!$chat instanceof Chat || !$user instanceof User || $chat->getUserId() !== $user->getId()) {
            return null;
        }

        $last = null;
        foreach ($this->segmenter->segment($chunks) as $turn) {
            $last = $this->storeTurn($session, $chat, $user, $turn);
        }

        return $last;
    }

    /**
     * Put the live model's usage since the previous reply on the latest
     * spoken answer. Returns that message only when something was added.
     */
    public function attributeVoice(VoiceSession $session): ?Message
    {
        if ($this->usage->isIncognito($session) || null === $session->getChatId()) {
            return null;
        }
        $message = $this->latestSpokenReply($session);
        if (!$message instanceof Message) {
            return null;
        }
        $delta = $this->usage->claimVoiceUsage($session);
        if (null === $delta) {
            return null;
        }
        $this->stamp->mergeLiveUsage($message, $delta);
        $this->em->flush();

        return $message;
    }

    /**
     * @param array{role: string, text: string, startMs: int, endMs: int} $turn
     */
    private function storeTurn(VoiceSession $session, Chat $chat, User $user, array $turn): Message
    {
        $direction = 'assistant' === $turn['role'] ? 'OUT' : 'IN';
        $latest = $this->messages->findOneBy(['chatId' => $chat->getId()], ['id' => 'DESC']);
        if ($latest instanceof Message && $latest->getDirection() === $direction) {
            if ($latest->getText() === $turn['text']) {
                if ('OUT' === $direction) {
                    $this->describeSpokenReply($session, $latest);
                    $this->em->flush();
                    $this->attributeVoice($session);
                }

                return $latest;
            }
            $merged = $latest->getMeta('voice_session_id') === (string) $session->getId()
                ? $this->segmenter->sameTurn($latest->getText(), $turn['text'])
                : null;
            if (null !== $merged) {
                $latest->setText($merged);

                return $this->finishTurn($session, $chat, $user, $latest);
            }
        }

        $locale = substr($user->getLocale(), 0, 2);
        $message = (new Message())
            ->setUserId((int) $user->getId())
            ->setChat($chat)
            ->setTrackingId((int) (microtime(true) * 1000))
            ->setProviderIndex('WEB')
            ->setUnixTimestamp(time())
            ->setDateTime(date('YmdHis'))
            ->setMessageType('WEB')
            ->setFile(0)
            ->setFilePath('')
            ->setFileType('')
            ->setTopic('CHAT')
            ->setLanguage(2 === strlen($locale) ? $locale : 'en')
            ->setText($turn['text'])
            ->setDirection($direction)
            ->setStatus('complete')
            ->setFileText('');
        $message->setMeta('source', 'live_voice');
        $message->setMeta('voice_session_id', (string) $session->getId());
        $message->setMeta('voice_provider', $session->getProvider());
        $model = $this->em->find(Model::class, $session->getModelId());
        $message->setMeta('voice_model', $model instanceof Model ? $model->getProviderId() : '');
        $message->setMeta('start_ms', (string) $turn['startMs']);
        $message->setMeta('end_ms', (string) $turn['endMs']);

        $this->em->persist($message);
        $stored = $this->finishTurn($session, $chat, $user, $message);
        $this->logger->info('Live voice transcript turn stored', [
            'session_id' => $session->getId(),
            'role' => $turn['role'],
        ]);

        return $stored;
    }

    private function finishTurn(VoiceSession $session, Chat $chat, User $user, Message $message): Message
    {
        if ('OUT' === $message->getDirection()) {
            $this->describeSpokenReply($session, $message);
        }
        $chat->updateTimestamp();
        $this->em->flush();
        // The first user sentence is stored before the answer exists, so a
        // title is only possible once the reply is in the same chat.
        $this->titleIfNeeded($chat, $user);
        if ('OUT' === $message->getDirection()) {
            $this->attributeVoice($session);
        }

        return $message;
    }

    /**
     * Name the live model on the spoken answer. When Synaplan was asked,
     * also copy that model's topic, tokens and cost. A reply that already
     * carries one answer does not take the next one.
     */
    private function describeSpokenReply(VoiceSession $session, Message $message): void
    {
        $model = $this->em->find(Model::class, $session->getModelId());
        if ($model instanceof Model) {
            $this->stamp->applyVoiceModel($message, $model);
        }
        if ('1' === $message->getMeta('voice_reply_applied')) {
            return;
        }
        $pending = $this->usage->takePendingReply($session);
        if (null === $pending) {
            return;
        }
        $this->stamp->apply($message, $pending);
    }

    private function latestSpokenReply(VoiceSession $session): ?Message
    {
        $chatId = $session->getChatId();
        if (null === $chatId) {
            return null;
        }
        $recent = $this->messages->findBy(['chatId' => $chatId], ['id' => 'DESC'], 8);
        foreach ($recent as $candidate) {
            if ('OUT' === $candidate->getDirection()
                && $candidate->getMeta('voice_session_id') === (string) $session->getId()) {
                return $candidate;
            }
        }

        return null;
    }

    private function titleIfNeeded(Chat $chat, User $user): void
    {
        try {
            $this->titles->titleWebChatIfNeeded($chat, (int) $user->getId());
        } catch (\Throwable $e) {
            $this->logger->warning('Live voice chat title failed', [
                'chat_id' => $chat->getId(),
                'error' => $e::class,
            ]);
        }
    }
}
