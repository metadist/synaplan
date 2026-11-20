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
 * Posts a finished chat run into its chat, once, on the server. The text is
 * what the run already said while it worked, then its answer when that was
 * not said as it came; a run that ended without one gets the one true
 * sentence for why it ended. The note of the check before delivery closes it.
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
        $text = self::answered($state);
        $note = $state->note ?? '';
        if ('' === trim($note)) {
            return $text;
        }

        return $text.self::gap($text).$note;
    }

    /** The part of {@see text()} a stream that showed everything the run said has not shown yet. */
    public static function rest(ChatRunState $state): string
    {
        $text = self::text($state);

        return str_starts_with($text, $state->said) ? substr($text, \strlen($state->said)) : $text;
    }

    /** What the run said, then its answer when that was not said as it came. */
    private static function answered(ChatRunState $state): string
    {
        $said = $state->said;
        $last = self::last($state);
        if ('' === trim($said)) {
            return $last;
        }
        if (str_ends_with(rtrim($said), rtrim($last))) {
            return $said;
        }

        return $said.self::gap($said).$last;
    }

    private static function gap(string $text): string
    {
        return str_ends_with($text, "\n\n") ? '' : "\n\n";
    }

    /** The answer, or the sentence for why the run ended without one. */
    private static function last(ChatRunState $state): string
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
        $reply = $this->replyRow($state)
            ?? $this->metas->findMessageInChatByMeta((int) $chat->getId(), self::META, $state->runId)
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

    /** The message the turn wrote for the answer, when it is an answer in this run's chat. */
    private function replyRow(ChatRunState $state): ?Message
    {
        $row = null === $state->replyMessageId ? null : $this->messages->find($state->replyMessageId);
        if (!$row instanceof Message || $row->getChatId() !== $state->chatId || $row->getUserId() !== $state->userId || 'OUT' !== $row->getDirection()) {
            return null;
        }

        return $row;
    }
}
