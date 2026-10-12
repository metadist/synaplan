<?php

declare(strict_types=1);

namespace App\Service\Voice;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Entity\VoiceSession;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\Chat\ChatTitleService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Writes closed transcript turns into the chat. Incognito writes nothing.
 * A repeated turn (same speaker, same text) is stored once.
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
    ) {
    }

    /**
     * @param list<array{role?: mixed, text?: mixed, startMs?: mixed, endMs?: mixed}> $chunks
     * @param array{provider?: string, model?: string}|null                           $answerModel
     */
    public function record(VoiceSession $session, array $chunks, ?array $answerModel = null): ?Message
    {
        if ($this->usage->isIncognito($session) || null === $session->getChatId()) {
            return null;
        }

        $chat = $this->em->find(Chat::class, $session->getChatId());
        $user = $this->users->find($session->getUserId());
        if (!$chat instanceof Chat || !$user instanceof User || $chat->getUserId() !== $user->getId()) {
            return null;
        }

        $last = null;
        foreach ($this->segmenter->segment($chunks) as $turn) {
            $last = $this->storeTurn($session, $chat, $user, $turn, $answerModel);
        }

        return $last;
    }

    /**
     * @param array{role: string, text: string, startMs: int, endMs: int} $turn
     * @param array{provider?: string, model?: string}|null               $answerModel
     */
    private function storeTurn(VoiceSession $session, Chat $chat, User $user, array $turn, ?array $answerModel): Message
    {
        $direction = 'assistant' === $turn['role'] ? 'OUT' : 'IN';
        $latest = $this->messages->findOneBy(['chatId' => $chat->getId()], ['id' => 'DESC']);
        if ($latest instanceof Message && $latest->getDirection() === $direction && $latest->getText() === $turn['text']) {
            return $latest;
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
        $model = $this->em->find(\App\Entity\Model::class, $session->getModelId());
        $message->setMeta('voice_model', $model instanceof \App\Entity\Model ? $model->getProviderId() : '');
        $message->setMeta('start_ms', (string) $turn['startMs']);
        $message->setMeta('end_ms', (string) $turn['endMs']);
        if ('OUT' === $direction && is_array($answerModel)) {
            $message->setMeta('ai_chat_provider', (string) ($answerModel['provider'] ?? ''));
            $message->setMeta('ai_chat_model', (string) ($answerModel['model'] ?? ''));
        }

        $this->em->persist($message);
        $this->em->flush();

        if ('IN' === $direction) {
            $this->titleIfNeeded($chat, $user);
        }

        $this->logger->info('Live voice transcript turn stored', [
            'session_id' => $session->getId(),
            'role' => $turn['role'],
        ]);

        return $message;
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
