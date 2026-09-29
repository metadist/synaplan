<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\TelegramBot;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Digest\MessageReferenceResolver;
use App\Service\Message\ChatErrorPresenter;
use App\Service\Message\MessageProcessor;
use App\Service\RateLimitService;
use App\Service\UserMemoryService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns one Telegram update into a chat turn for the paired owner.
 * Strangers and non-text messages get one sentence and nothing is stored.
 */
final readonly class TelegramInboundService
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $users,
        private TelegramConnectionService $connections,
        private TelegramBotApi $api,
        private MessageProcessor $processor,
        private ChatErrorPresenter $errors,
        private RateLimitService $rateLimits,
        private UserMemoryService $memories,
        private MessageReferenceResolver $references,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $update
     */
    public function handle(int $botRowId, array $update): void
    {
        $bot = $this->em->find(TelegramBot::class, $botRowId);
        if (!$bot instanceof TelegramBot) {
            return;
        }
        $incoming = $update['message'] ?? null;
        if (!is_array($incoming)) {
            return;
        }
        $chatPayload = $incoming['chat'] ?? null;
        $from = $incoming['from'] ?? null;
        if (!is_array($chatPayload) || !is_array($from)) {
            return;
        }

        $tgChatId = $this->scalarId($chatPayload['id'] ?? null);
        $tgUserId = $this->scalarId($from['id'] ?? null);
        if (null === $tgChatId || null === $tgUserId) {
            return;
        }

        $token = $this->connections->revealToken($bot);
        if (null === $token) {
            return;
        }

        if ('private' !== (string) ($chatPayload['type'] ?? '')) {
            $this->reply($bot, $token, $tgChatId, 'This bot only answers private chats.');

            return;
        }

        if (!$this->isTextMessage($incoming)) {
            $this->reply($bot, $token, $tgChatId, 'Only text messages for now.');

            return;
        }

        $owner = $this->users->find($bot->getOwnerId());
        if (!$owner instanceof User) {
            return;
        }

        $text = trim((string) $incoming['text']);
        if (preg_match('/^\/start(?:@\w+)?(?:\s+(\S+))?$/u', $text, $matches)) {
            $this->handleStart($bot, $owner, $token, $tgUserId, $tgChatId, $matches[1] ?? '', $incoming);

            return;
        }

        if (TelegramBot::STATUS_CONNECTED !== $bot->getStatus() || $bot->getTgUserId() !== $tgUserId) {
            $sentence = TelegramBot::STATUS_PENDING === $bot->getStatus()
                ? 'Finish connecting with the link from Channels.'
                : 'This bot only answers its owner.';
            $this->reply($bot, $token, $tgChatId, $sentence);

            return;
        }

        $this->answer($bot, $owner, $token, $tgChatId, $text, $incoming);
    }

    /**
     * @param array<string, mixed> $incoming
     */
    private function handleStart(
        TelegramBot $bot,
        User $owner,
        string $token,
        string $tgUserId,
        string $tgChatId,
        string $code,
        array $incoming,
    ): void {
        if (TelegramBot::STATUS_CONNECTED === $bot->getStatus() && $bot->getTgUserId() === $tgUserId) {
            $this->reply($bot, $token, $tgChatId, 'You are already connected. Send a message to get a reply.');

            return;
        }
        if (TelegramBot::STATUS_CONNECTED === $bot->getStatus()) {
            $this->reply($bot, $token, $tgChatId, 'This bot only answers its owner.');

            return;
        }
        if (!$this->connections->pair($bot, $code, $tgUserId, $tgChatId)) {
            $this->reply($bot, $token, $tgChatId, 'That code does not match. Use the link from Channels again.');

            return;
        }

        $chat = $this->chatFor($bot, $owner);
        $externalId = $this->scalarId($incoming['message_id'] ?? null);
        $this->storeMessage($owner, $chat, 'Connected from Telegram.', 'IN', 'complete', $externalId, $tgChatId);
        $reply = 'Connected. Send a message whenever you want a reply.';
        $bot->touch();
        $this->storeMessage($owner, $chat, $reply, 'OUT', 'complete', null, $tgChatId);
        $this->reply($bot, $token, $tgChatId, $reply);
    }

    /**
     * @param array<string, mixed> $incoming
     */
    private function answer(
        TelegramBot $bot,
        User $owner,
        string $token,
        string $tgChatId,
        string $text,
        array $incoming,
    ): void {
        $chat = $this->chatFor($bot, $owner);
        $externalId = $this->scalarId($incoming['message_id'] ?? null);
        $limit = $this->rateLimits->checkLimit($owner, 'MESSAGES');
        if (empty($limit['allowed'])) {
            $sentence = 'You have reached the message limit. Try again later.';
            $this->storeMessage($owner, $chat, $text, 'IN', 'complete', $externalId, $tgChatId);
            $this->storeMessage($owner, $chat, $sentence, 'OUT', 'complete', null, $tgChatId);
            $this->reply($bot, $token, $tgChatId, $sentence);

            return;
        }

        $message = $this->storeMessage($owner, $chat, $text, 'IN', 'processing', $externalId, $tgChatId);

        try {
            $this->api->sendChatAction($token, $tgChatId);
        } catch (TelegramChannelException $e) {
            $this->noteDeliveryFailure($bot, $e);
        }

        try {
            $result = $this->processor->process($message);
        } catch (\Throwable $e) {
            $this->logger->error('Telegram message processing failed', [
                'message_id' => $message->getId(),
                'error' => $e->getMessage(),
            ]);
            $this->finish($bot, $owner, $chat, $message, $token, $tgChatId, 'Something went wrong. Try sending the message again.', null);

            return;
        }

        if (empty($result['success'])) {
            $sentence = $this->errors->presentFromResult($result, $message->getLanguage() ?: 'en', false)->userText;
            $this->finish($bot, $owner, $chat, $message, $token, $tgChatId, $sentence, null);

            return;
        }

        $response = is_array($result['response'] ?? null) ? $result['response'] : [];
        $reply = trim((string) ($response['content'] ?? ''));
        if ('' === $reply) {
            $reply = 'I could not write a reply. Try sending the message again.';
        }
        $reply = $this->memories->resolveMemoryTags($reply, $owner);
        $reply = $this->references->resolveMessageTags($reply, $owner);

        $metadata = is_array($response['metadata'] ?? null) ? $response['metadata'] : [];
        try {
            $this->rateLimits->recordUsage($owner, 'MESSAGES', [
                'provider' => $metadata['provider'] ?? 'unknown',
                'model' => $metadata['model'] ?? 'unknown',
                'usage' => $metadata['usage'] ?? [],
                'model_id' => $metadata['model_id'] ?? null,
                'source' => 'TELEGRAM',
                'response_text' => $reply,
                'input_text' => $text,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Telegram usage record failed', [
                'user_id' => $owner->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        $this->finish($bot, $owner, $chat, $message, $token, $tgChatId, $reply, null);
    }

    private function finish(
        TelegramBot $bot,
        User $owner,
        Chat $chat,
        Message $inbound,
        string $token,
        string $tgChatId,
        string $reply,
        ?string $externalId,
    ): void {
        if ('processing' === $inbound->getStatus()) {
            $inbound->setStatus('complete');
            $this->em->flush();
        }
        $bot->touch();
        $this->storeMessage($owner, $chat, $reply, 'OUT', 'complete', $externalId, $tgChatId);
        $this->reply($bot, $token, $tgChatId, $reply);
    }

    private function chatFor(TelegramBot $bot, User $owner): Chat
    {
        $chatId = $bot->getChatId();
        if (null !== $chatId) {
            $existing = $this->em->find(Chat::class, $chatId);
            if (
                $existing instanceof Chat
                && $existing->getUserId() === (int) $owner->getId()
                && 'telegram' === $existing->getSource()
            ) {
                return $existing;
            }
        }

        $chat = new Chat();
        $chat->setUserId((int) $owner->getId());
        $chat->setTitle('Telegram: @'.$bot->getBotUsername());
        $chat->setSource('telegram');
        $this->em->persist($chat);
        $this->em->flush();
        $id = $chat->getId();
        if (null === $id) {
            throw new \RuntimeException('Telegram chat persist did not assign an id');
        }
        $this->connections->attachChat($bot, $id);

        return $chat;
    }

    private function storeMessage(
        User $owner,
        Chat $chat,
        string $text,
        string $direction,
        string $status,
        ?string $externalId,
        string $tgChatId,
    ): Message {
        $now = time();
        $message = new Message();
        $message->setUserId((int) $owner->getId());
        $message->setChat($chat);
        $message->setTrackingId($now);
        $message->setProviderIndex('TELEGRAM');
        $message->setUnixTimestamp($now);
        $message->setDateTime(date('YmdHis', $now));
        $message->setMessageType('TGRM');
        $message->setFile(0);
        $message->setTopic('CHAT');
        $message->setLanguage('en');
        $message->setText($text);
        $message->setDirection($direction);
        $message->setStatus($status);
        $this->em->persist($message);
        $this->em->flush();
        $message->setMeta('channel', 'telegram');
        if (null !== $externalId && '' !== $externalId) {
            $message->setMeta('external_id', $externalId);
        }
        $message->setMeta('tg_chat_id', $tgChatId);
        $chat->updateTimestamp();
        $this->em->flush();

        return $message;
    }

    private function reply(TelegramBot $bot, string $token, string $tgChatId, string $text): void
    {
        try {
            $this->api->sendMessage($token, $tgChatId, $text);
        } catch (TelegramChannelException $e) {
            $this->noteDeliveryFailure($bot, $e);
        }
    }

    private function noteDeliveryFailure(TelegramBot $bot, TelegramChannelException $e): void
    {
        if (!in_array($e->errorCode, [
            TelegramChannelException::TOKEN_REVOKED,
            TelegramChannelException::BOT_BLOCKED,
            TelegramChannelException::SEND_FAILED,
        ], true)) {
            return;
        }
        $this->connections->markError($bot, $e->errorCode);
    }

    /**
     * @param array<string, mixed> $incoming
     */
    private function isTextMessage(array $incoming): bool
    {
        foreach (['photo', 'voice', 'audio', 'video', 'video_note', 'document', 'sticker', 'animation', 'location', 'venue', 'contact', 'poll', 'dice'] as $key) {
            if (isset($incoming[$key])) {
                return false;
            }
        }

        return isset($incoming['text']) && is_string($incoming['text']) && '' !== trim($incoming['text']);
    }

    private function scalarId(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value) && $value > 0 && floor($value) === $value) {
            return sprintf('%.0f', $value);
        }
        if (is_string($value) && '' !== $value && ctype_digit($value)) {
            return $value;
        }

        return null;
    }
}
