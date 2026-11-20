<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Entity\Chat;
use App\Entity\Message;
use App\Repository\ChatRepository;
use App\Repository\MessageMetaRepository;
use App\Repository\MessageRepository;
use App\Service\Run\RunDelivery;
use App\Service\Run\RunKeys;
use App\Service\Run\RunKeyspace;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Posts a finished chat run into its chat, once, on the server. The answer
 * is the run's own; a run that ended without one gets the one true sentence
 * for why it ended.
 */
final readonly class ChatRunDelivery
{
    public const META = 'chat_run_id';

    private RunDelivery $once;

    public function __construct(
        RunKeys $keys,
        private ChatRepository $chats,
        private MessageRepository $messages,
        private MessageMetaRepository $metas,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
        $this->once = new RunDelivery($keys, RunKeyspace::runs());
    }

    public function deliver(ChatRunState $state): bool
    {
        if (!$state->isTerminal()) {
            return false;
        }

        return $this->once->once($state->runId, function () use ($state): void {
            $chat = $this->chats->find($state->chatId);
            if (!$chat instanceof Chat || $chat->getUserId() !== $state->userId) {
                return;
            }
            $this->post($state, $chat);
        });
    }

    public static function text(ChatRunState $state): string
    {
        if (null !== $state->answer && '' !== trim($state->answer)) {
            return $state->answer;
        }
        $sentence = ($state->end ?? ChatRunEnd::NoProgress)->sentence($state->locale);

        return '' !== $sentence ? $sentence : ChatRunEnd::NoProgress->sentence($state->locale);
    }

    private function post(ChatRunState $state, Chat $chat): void
    {
        $now = $this->clock->now()->getTimestamp();
        $reply = $this->metas->findMessageInChatByMeta((int) $chat->getId(), self::META, $state->runId)
            ?? (new Message())
                ->setUserId($state->userId)
                ->setChat($chat)
                ->setTrackingId($now)
                ->setProviderIndex('CHAT_RUN')
                ->setUnixTimestamp($now)
                ->setDateTime(date('YmdHis', $now))
                ->setMessageType('WEB')
                ->setFile(0)
                ->setTopic('CHAT')
                ->setLanguage(substr($state->locale, 0, 2))
                ->setDirection('OUT');
        $reply->setText(self::text($state))
            ->setStatus('complete')
            ->setMeta(self::META, $state->runId);
        $this->em->persist($reply);
        $incoming = null === $state->incomingMessageId ? null : $this->messages->find($state->incomingMessageId);
        if ($incoming instanceof Message && $incoming->getChatId() === $state->chatId && 'IN' === $incoming->getDirection()) {
            $incoming->setStatus('complete');
        }
        $chat->updateTimestamp();
        $this->em->flush();
    }
}
