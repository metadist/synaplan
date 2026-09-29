<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\TelegramBot;
use App\Entity\User;
use App\Realtime\Notifier\ChatActivityNotifier;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\Digest\MessageReferenceResolver;
use App\Service\Message\ChatErrorPresenter;
use App\Service\Message\MessageProcessor;
use App\Service\RateLimitService;
use App\Service\UserMemoryService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns one Telegram update into a chat turn for the paired owner.
 * Strangers and non-text messages get one sentence and nothing is stored.
 * A redelivered update (Messenger retry) that already stored its turn is skipped.
 */
final readonly class TelegramInboundService
{
    public const META_UPDATE = 'tg_update';

    private const SUPPORTED_LOCALES = ['de', 'en', 'es', 'fr', 'tr'];
    private const LOCK_SECONDS = 300.0;
    private const DOMAIN = 'telegram';

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $users,
        private MessageRepository $messages,
        private TelegramConnectionService $connections,
        private TelegramBotApi $api,
        private MessageProcessor $processor,
        private ChatErrorPresenter $errors,
        private RateLimitService $rateLimits,
        private UserMemoryService $memories,
        private MessageReferenceResolver $references,
        private ChatActivityNotifier $activity,
        private TranslatorInterface $translator,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $update
     */
    public function handle(int $botRowId, int $updateId, array $update): void
    {
        $bot = $this->em->find(TelegramBot::class, $botRowId);
        if (!$bot instanceof TelegramBot) {
            return;
        }

        $updateKey = $bot->getBotId().':'.$updateId;
        $lock = $this->lockFactory->createLock('telegram_turn_'.$botRowId.'_'.$updateId, self::LOCK_SECONDS);
        if (!$lock->acquire()) {
            return;
        }
        try {
            if ($this->messages->hasTelegramUpdate($bot->getOwnerId(), self::META_UPDATE, $updateKey)) {
                return;
            }
            $this->process($bot, $updateKey, $update);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param array<string, mixed> $update
     */
    private function process(TelegramBot $bot, string $updateKey, array $update): void
    {
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

        $owner = $this->users->find($bot->getOwnerId());
        if (!$owner instanceof User) {
            return;
        }

        $token = $this->connections->revealToken($bot);
        if (null === $token) {
            return;
        }

        $turn = new TelegramTurn($bot, $owner, $token, $tgChatId, $updateKey, $this->localeFor($bot, $owner, $tgUserId, $from));

        if ('private' !== (string) ($chatPayload['type'] ?? '')) {
            $this->reply($turn, $this->say($turn, 'private_only'));

            return;
        }

        if (!$this->isTextMessage($incoming)) {
            $this->reply($turn, $this->say($turn, 'text_only'));

            return;
        }

        $text = trim((string) $incoming['text']);
        if (preg_match('/^\/start(?:@\w+)?(?:\s+(\S+))?$/u', $text, $matches)) {
            $this->handleStart($turn, $tgUserId, $matches[1] ?? '', $incoming);

            return;
        }

        if (TelegramBot::STATUS_CONNECTED !== $bot->getStatus() || $bot->getTgUserId() !== $tgUserId) {
            $key = TelegramBot::STATUS_PENDING === $bot->getStatus() ? 'finish_pairing' : 'owner_only';
            $this->reply($turn, $this->say($turn, $key));

            return;
        }

        $this->answer($turn, $text, $incoming);
    }

    /**
     * @param array<string, mixed> $incoming
     */
    private function handleStart(TelegramTurn $turn, string $tgUserId, string $code, array $incoming): void
    {
        $bot = $turn->bot;
        if (TelegramBot::STATUS_CONNECTED === $bot->getStatus() && $bot->getTgUserId() === $tgUserId) {
            $this->reply($turn, $this->say($turn, 'already_connected'));

            return;
        }
        if (TelegramBot::STATUS_CONNECTED === $bot->getStatus()) {
            $this->reply($turn, $this->say($turn, 'owner_only'));

            return;
        }
        if (!$this->connections->pair($bot, $code, $tgUserId, $turn->tgChatId)) {
            $this->reply($turn, $this->say($turn, 'code_mismatch'));

            return;
        }

        $chat = $this->chatFor($turn);
        $externalId = $this->scalarId($incoming['message_id'] ?? null);
        $this->storeMessage($turn, $chat, $this->say($turn, 'connected_in'), 'IN', 'complete', $externalId);
        $reply = $this->say($turn, 'connected');
        $bot->touch();
        $this->storeMessage($turn, $chat, $reply, 'OUT', 'complete', null);
        $this->reply($turn, $reply);
    }

    /**
     * @param array<string, mixed> $incoming
     */
    private function answer(TelegramTurn $turn, string $text, array $incoming): void
    {
        $owner = $turn->owner;
        $chat = $this->chatFor($turn);
        $externalId = $this->scalarId($incoming['message_id'] ?? null);
        $limit = $this->rateLimits->checkLimit($owner, 'MESSAGES');
        if (empty($limit['allowed'])) {
            $sentence = $this->say($turn, 'limit_reached');
            $this->storeMessage($turn, $chat, $text, 'IN', 'complete', $externalId);
            $this->storeMessage($turn, $chat, $sentence, 'OUT', 'complete', null);
            $this->reply($turn, $sentence);

            return;
        }

        $message = $this->storeMessage($turn, $chat, $text, 'IN', 'processing', $externalId);
        $this->activity->publishActivity($chat, (int) $owner->getId(), 'IN', $text);

        try {
            $this->api->sendChatAction($turn->token, $turn->tgChatId);
        } catch (TelegramChannelException $e) {
            $this->noteDeliveryFailure($turn->bot, $e);
        }

        try {
            $result = $this->processor->process($message);
        } catch (\Throwable $e) {
            $this->logger->error('Telegram message processing failed', [
                'message_id' => $message->getId(),
                'error' => $e->getMessage(),
            ]);
            $this->finish($turn, $chat, $message, $this->say($turn, 'failed'));

            return;
        }

        if (empty($result['success'])) {
            $sentence = $this->errors->presentFromResult($result, $turn->locale, false)->userText;
            $this->finish($turn, $chat, $message, $sentence);

            return;
        }

        $response = is_array($result['response'] ?? null) ? $result['response'] : [];
        $reply = trim((string) ($response['content'] ?? ''));
        if ('' === $reply) {
            $reply = $this->say($turn, 'empty_reply');
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

        $this->finish($turn, $chat, $message, $reply);
    }

    private function finish(TelegramTurn $turn, Chat $chat, Message $inbound, string $reply): void
    {
        if ('processing' === $inbound->getStatus()) {
            $inbound->setStatus('complete');
            $this->em->flush();
        }
        $turn->bot->touch();
        $this->storeMessage($turn, $chat, $reply, 'OUT', 'complete', null);
        $this->reply($turn, $reply);
    }

    private function chatFor(TelegramTurn $turn): Chat
    {
        $bot = $turn->bot;
        $title = 'Telegram: @'.$bot->getBotUsername();
        $chatId = $bot->getChatId();
        if (null !== $chatId) {
            $existing = $this->em->find(Chat::class, $chatId);
            if (
                $existing instanceof Chat
                && $existing->getUserId() === (int) $turn->owner->getId()
                && 'telegram' === $existing->getSource()
            ) {
                if ($existing->getTitle() !== $title) {
                    $existing->setTitle($title);
                    $this->em->flush();
                }

                return $existing;
            }
        }

        $chat = new Chat();
        $chat->setUserId((int) $turn->owner->getId());
        $chat->setTitle($title);
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
        TelegramTurn $turn,
        Chat $chat,
        string $text,
        string $direction,
        string $status,
        ?string $externalId,
    ): Message {
        $now = time();
        $message = new Message();
        $message->setUserId((int) $turn->owner->getId());
        $message->setChat($chat);
        $message->setTrackingId($now);
        $message->setProviderIndex('TELEGRAM');
        $message->setUnixTimestamp($now);
        $message->setDateTime(date('YmdHis', $now));
        $message->setMessageType('TGRM');
        $message->setFile(0);
        $message->setTopic('CHAT');
        $message->setLanguage($turn->locale);
        $message->setText($text);
        $message->setDirection($direction);
        $message->setStatus($status);

        // The row and its update marker land together, so a retried job
        // either finds the marker or finds nothing stored at all.
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $this->em->persist($message);
            $this->em->flush();
            $message->setMeta('channel', 'telegram');
            if (null !== $externalId && '' !== $externalId) {
                $message->setMeta('external_id', $externalId);
            }
            $message->setMeta('tg_chat_id', $turn->tgChatId);
            if ('IN' === $direction) {
                $message->setMeta(self::META_UPDATE, $turn->updateKey);
            }
            $chat->updateTimestamp();
            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        return $message;
    }

    private function reply(TelegramTurn $turn, string $text): void
    {
        try {
            $this->api->sendMessage($turn->token, $turn->tgChatId, $text);
        } catch (TelegramChannelException $e) {
            $this->noteDeliveryFailure($turn->bot, $e);
        }
    }

    /**
     * Only a revoked token or a blocked bot changes the card. A timeout or a
     * rate limit must not lock the owner out of the next message.
     */
    private function noteDeliveryFailure(TelegramBot $bot, TelegramChannelException $e): void
    {
        if (!in_array($e->errorCode, [
            TelegramChannelException::TOKEN_REVOKED,
            TelegramChannelException::BOT_BLOCKED,
        ], true)) {
            $this->logger->warning('Telegram delivery failed', [
                'bot_id' => $bot->getId(),
                'error' => $e->errorCode,
            ]);

            return;
        }
        $this->connections->markError($bot, $e->errorCode);
    }

    /**
     * The owner reads their app language. Anyone else gets their Telegram
     * language when we support it, else the owner's.
     *
     * @param array<string, mixed> $from
     */
    private function localeFor(TelegramBot $bot, User $owner, string $tgUserId, array $from): string
    {
        $isOwner = TelegramBot::STATUS_PENDING === $bot->getStatus() || $bot->getTgUserId() === $tgUserId;
        if ($isOwner) {
            return $owner->getLocale();
        }
        $code = $from['language_code'] ?? null;
        if (is_string($code)) {
            $language = strtolower(substr($code, 0, 2));
            if (in_array($language, self::SUPPORTED_LOCALES, true)) {
                return $language;
            }
        }

        return $owner->getLocale();
    }

    private function say(TelegramTurn $turn, string $key): string
    {
        return $this->translator->trans('telegram.'.$key, [], self::DOMAIN, $turn->locale);
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
