<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use App\Entity\Message;
use App\Entity\TelegramBot;
use App\Entity\User;
use App\Message\ProcessTelegramAlbumCommand;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Routes one Telegram update: a message becomes a chat turn for the paired
 * owner, an edit answers again, a button acts on an answer. Strangers get
 * one sentence and nothing is stored. A redelivered update is skipped once
 * its turn ended and resumed while its turn is still unfinished.
 */
final readonly class TelegramInboundService
{
    public const META_UPDATE = TelegramMessageStore::META_UPDATE;

    private const SUPPORTED_LOCALES = ['de', 'en', 'es', 'fr', 'tr'];
    private const LOCK_SECONDS = 300.0;
    private const BUSY_RETRY_MS = 30_000;
    /** Telegram sends the parts of an album within about a second. */
    private const ALBUM_WAIT_MS = 1500;

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $users,
        private MessageRepository $messages,
        private TelegramConnectionService $connections,
        private TelegramMessageStore $store,
        private TelegramConversation $conversation,
        private TelegramCallbackService $callbacks,
        private TelegramAlbumBuffer $albums,
        private TelegramState $state,
        private TelegramCopy $copy,
        private MessageBusInterface $bus,
        private LockFactory $lockFactory,
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

        $this->connections->ensureCommandMenu($bot);

        $updateKey = $bot->getBotId().':'.$updateId;
        $lock = $this->lockFactory->createLock('telegram_turn_'.$botRowId.'_'.$updateId, self::LOCK_SECONDS);
        if (!$lock->acquire()) {
            // The holder may be a worker that died; the lock expires, so try again later instead of dropping the update.
            throw new RecoverableMessageHandlingException(sprintf('Telegram update %d of bot %d is locked by another worker.', $updateId, $botRowId), 0, null, self::BUSY_RETRY_MS);
        }
        $chatLock = null;
        try {
            if ($this->state->wasHandled($updateKey)) {
                return;
            }
            $stored = $this->messages->findTelegramUpdate($bot->getOwnerId(), self::META_UPDATE, $updateKey);
            if (null !== $stored && !$this->isUnfinished($stored)) {
                return;
            }
            $acknowledged = false;
            if (null !== $stored || $this->needsChatLock($update)) {
                $callback = is_array($update['callback_query'] ?? null) ? $update['callback_query'] : null;
                $chatLock = $this->chatLock(
                    $botRowId,
                    $this->updateChatId($update),
                    null === $callback ? null : function () use ($bot, $callback, &$acknowledged): void {
                        $acknowledged = $this->callbacks->acknowledgeEarly($bot, $callback);
                    },
                );
            }
            if (null !== $stored) {
                $this->resume($bot, $stored, $update['message'] ?? null, $updateKey);
            } else {
                $this->process($bot, $updateKey, $updateId, $update, $acknowledged);
            }
        } finally {
            $chatLock?->release();
            $lock->release();
        }
    }

    /**
     * A turn stores its message as "processing" and always moves it on, even
     * on error. A row still processing under a free update lock therefore
     * belongs to a worker that was killed mid-turn.
     */
    private function isUnfinished(Message $inbound): bool
    {
        return 'processing' === $inbound->getStatus();
    }

    private function resume(TelegramBot $bot, Message $inbound, mixed $message, string $updateKey): void
    {
        $turn = is_array($message) ? $this->ownerTurn($bot, $message, $updateKey) : null;
        if (null !== $turn) {
            $this->conversation->resume($turn, $inbound);
        }
    }

    /**
     * Album parts only land in the buffer (the album turn takes the lock
     * itself), and an edit of shared content only moves the stored pin, so
     * neither waits for a running answer.
     *
     * @param array<string, mixed> $update
     */
    private function needsChatLock(array $update): bool
    {
        if (isset($update['message']['media_group_id'])) {
            return false;
        }
        $edited = $update['edited_message'] ?? null;

        return !is_array($edited) || !TelegramStructuredInput::isShared($edited);
    }

    /**
     * Turns of one chat run one after another, so an edit or a button press
     * never races the answer it refers to. $whileWaiting runs once when
     * another turn holds the chat.
     */
    private function chatLock(int $botRowId, ?string $chatId, ?\Closure $whileWaiting = null): ?LockInterface
    {
        if (null === $chatId) {
            return null;
        }
        $lock = $this->lockFactory->createLock('telegram_chat_'.$botRowId.'_'.$chatId, self::LOCK_SECONDS);
        if (!$lock->acquire()) {
            if (null !== $whileWaiting) {
                $whileWaiting();
            }
            $lock->acquire(true);
        }

        return $lock;
    }

    /**
     * @param array<string, mixed> $update
     */
    private function updateChatId(array $update): ?string
    {
        $message = $update['message'] ?? $update['edited_message'] ?? ($update['callback_query']['message'] ?? null);
        $chatId = is_array($message) && is_array($message['chat'] ?? null) ? ($message['chat']['id'] ?? null) : null;

        return is_int($chatId) || is_string($chatId) ? (string) $chatId : null;
    }

    public function handleAlbum(int $botRowId, string $groupId): void
    {
        $bot = $this->em->find(TelegramBot::class, $botRowId);
        if (!$bot instanceof TelegramBot) {
            return;
        }
        $parts = $this->albums->take($botRowId, $groupId);
        if ([] === $parts) {
            return;
        }

        $chatLock = $this->chatLock($botRowId, $this->updateChatId(['message' => reset($parts)]));
        try {
            $this->answerAlbum($bot, $parts);
            $this->albums->complete($botRowId, $groupId);
        } finally {
            $chatLock?->release();
        }
    }

    /**
     * @param array<int, array<string, mixed>> $parts
     */
    private function answerAlbum(TelegramBot $bot, array $parts): void
    {
        $updateIds = array_keys($parts);
        $firstKey = $bot->getBotId().':'.min($updateIds);
        foreach ($updateIds as $updateId) {
            $this->state->markHandled($bot->getBotId().':'.$updateId);
        }
        $first = reset($parts);
        $stored = $this->messages->findTelegramUpdate($bot->getOwnerId(), self::META_UPDATE, $firstKey);
        if (null !== $stored) {
            if ($this->isUnfinished($stored)) {
                $this->resume($bot, $stored, $first, $firstKey);
            }

            return;
        }

        $turn = $this->ownerTurn($bot, $first, $firstKey);
        if (null === $turn) {
            return;
        }

        $say = $this->copy->sayer($turn->locale);
        $media = [];
        $caption = null;
        $externalId = null;
        foreach ($parts as $part) {
            $incoming = TelegramIncoming::fromMessage($part, $say);
            $externalId ??= $incoming->messageId;
            if (null !== $incoming->media) {
                $media[] = $incoming->media;
            }
            if (null === $caption && '' !== $incoming->text) {
                $caption = $this->commandPrompt($incoming->text);
            }
        }
        $this->conversation->answer($turn, $caption ?? TelegramIncoming::albumPrompt($media, $say), $media, $externalId);
    }

    /**
     * @param array<string, mixed> $update
     */
    private function process(TelegramBot $bot, string $updateKey, int $updateId, array $update, bool $acknowledged): void
    {
        if (is_array($update['callback_query'] ?? null)) {
            $this->state->markHandled($updateKey);
            $this->handleCallback($bot, $updateKey, $update['callback_query'], $acknowledged);

            return;
        }
        if (is_array($update['edited_message'] ?? null)) {
            $this->state->markHandled($updateKey);
            $this->handleEdit($bot, $updateKey, $update['edited_message']);

            return;
        }
        if (is_array($update['message'] ?? null)) {
            $this->handleMessage($bot, $updateKey, $updateId, $update['message']);
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private function handleMessage(TelegramBot $bot, string $updateKey, int $updateId, array $message): void
    {
        $context = $this->context($bot, $message['chat'] ?? null, $message['from'] ?? null, $updateKey);
        if (null === $context) {
            return;
        }
        [$turn, $tgUserId, $private] = $context;

        if (!$private) {
            $this->conversation->reply($turn, $this->conversation->say($turn, 'private_only'));

            return;
        }

        $incoming = TelegramIncoming::fromMessage($message, $this->copy->sayer($turn->locale));
        if ($incoming->isCommand() && preg_match('/^\/start(?:@\w+)?(?:\s+(\S+))?$/u', $incoming->text, $matches)) {
            $this->handleStart($turn, $tgUserId, $matches[1] ?? '', $incoming->messageId);

            return;
        }

        if (!$this->isOwner($bot, $tgUserId)) {
            $key = TelegramBot::STATUS_PENDING === $bot->getStatus() ? 'finish_pairing' : 'owner_only';
            $this->conversation->reply($turn, $this->conversation->say($turn, $key));

            return;
        }

        if ($incoming->isCommand() && preg_match('/^\/help(?:@\w+)?$/u', $incoming->text)) {
            $this->state->markHandled($updateKey);
            $this->state->clearFeedback((int) $bot->getId());
            $this->conversation->reply($turn, $this->conversation->say($turn, 'help'));

            return;
        }

        if (!$incoming->isCommand() && null === $incoming->media && null === $incoming->shared && '' !== $incoming->text
            && $this->callbacks->saveFeedback($turn, $incoming->text)) {
            $this->state->markHandled($updateKey);

            return;
        }
        $this->state->clearFeedback((int) $bot->getId());

        if ($incoming->isEmpty()) {
            $this->conversation->reply($turn, $this->conversation->say($turn, 'unsupported_message'));

            return;
        }

        if (null !== $incoming->mediaGroupId && null !== $incoming->media) {
            $first = $this->albums->add((int) $bot->getId(), $incoming->mediaGroupId, $updateId, $message);
            if (true === $first) {
                $this->bus->dispatch(
                    new ProcessTelegramAlbumCommand((int) $bot->getId(), $incoming->mediaGroupId),
                    [new DelayStamp(self::ALBUM_WAIT_MS)],
                );
            }
            if (null !== $first) {
                return;
            }
        }

        $this->conversation->answer(
            $turn,
            $this->commandPrompt($incoming->prompt()),
            null !== $incoming->media ? [$incoming->media] : [],
            $incoming->messageId,
            $incoming->payload,
        );
    }

    /**
     * An edited question is answered again. The newest question replaces its
     * answer; an older one gets a fresh reply so later turns stay intact.
     *
     * @param array<string, mixed> $message
     */
    private function handleEdit(TelegramBot $bot, string $updateKey, array $message): void
    {
        $context = $this->context($bot, $message['chat'] ?? null, $message['from'] ?? null, $updateKey);
        if (null === $context || !$context[2] || !$this->isOwner($bot, $context[1])) {
            return;
        }
        $turn = $context[0];
        $incoming = TelegramIncoming::fromMessage($message, $this->copy->sayer($turn->locale));
        if (null === $incoming->messageId) {
            return;
        }
        $inbound = $this->messages->findTelegramInbound((int) $turn->owner->getId(), $turn->tgChatId, $incoming->messageId);
        if (null === $inbound) {
            return;
        }

        // A live location sends an edit every few seconds; it only moves the pin.
        if (null !== $incoming->payload) {
            $this->store->updatePayload($inbound, $incoming->payload);

            return;
        }

        $prompt = $this->commandPrompt($incoming->prompt());
        if ('' === $prompt || $prompt === $inbound->getText()) {
            return;
        }
        $this->store->recordEdit($inbound, $prompt);

        $chatId = $inbound->getChatId();
        $isLatest = null !== $chatId && !$this->messages->hasInboundAfter($chatId, (int) $inbound->getId());
        $previous = $isLatest ? $this->messages->findTelegramAnswer((int) $turn->owner->getId(), (int) $inbound->getId()) : null;
        $this->conversation->regenerate($turn, $inbound, $previous, [], (int) $incoming->messageId);
    }

    /**
     * @param array<string, mixed> $callback
     */
    private function handleCallback(TelegramBot $bot, string $updateKey, array $callback, bool $acknowledged): void
    {
        $chat = $callback['message']['chat'] ?? null;
        $context = $this->context($bot, $chat, $callback['from'] ?? null, $updateKey);
        if (null === $context) {
            return;
        }
        [$turn, $tgUserId, $private] = $context;
        $fromOwner = $private && $this->isOwner($bot, $tgUserId) && $bot->getTgChatId() === $turn->tgChatId;
        $this->callbacks->handle($turn, $callback, $fromOwner, $acknowledged);
    }

    private function handleStart(TelegramTurn $turn, string $tgUserId, string $code, ?string $externalId): void
    {
        $bot = $turn->bot;
        if ($this->isOwner($bot, $tgUserId)) {
            $this->conversation->reply($turn, $this->conversation->say($turn, 'already_connected'));

            return;
        }
        if (TelegramBot::STATUS_CONNECTED === $bot->getStatus()) {
            $this->conversation->reply($turn, $this->conversation->say($turn, 'owner_only'));

            return;
        }

        $result = $this->connections->pair($bot, $code, $tgUserId, $turn->tgChatId);
        if (TelegramPairResult::Expired === $result) {
            $this->conversation->reply($turn, $this->conversation->say($turn, 'code_expired'));

            return;
        }
        if (TelegramPairResult::Paired !== $result) {
            $this->conversation->reply($turn, $this->conversation->say($turn, 'code_mismatch'));

            return;
        }

        $chat = $this->store->chatFor($turn);
        $this->store->store($turn, $chat, $this->conversation->say($turn, 'connected_in'), 'IN', 'complete', $externalId);
        $reply = $this->conversation->say($turn, 'connected');
        $this->store->store($turn, $chat, $reply, 'OUT', 'complete', null);
        $this->connections->noteExchange($bot);
        $this->conversation->reply($turn, $reply);
    }

    /**
     * Telegram appends the bot name to commands tapped in the menu
     * ("/pic@my_bot a cat"); the chat pipeline expects "/pic a cat".
     */
    private function commandPrompt(string $prompt): string
    {
        return (string) (preg_replace('/^(\/[a-z]+)@\w+/i', '$1', $prompt) ?? $prompt);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function ownerTurn(TelegramBot $bot, array $message, string $updateKey): ?TelegramTurn
    {
        $context = $this->context($bot, $message['chat'] ?? null, $message['from'] ?? null, $updateKey);
        if (null === $context || !$context[2] || !$this->isOwner($bot, $context[1])) {
            return null;
        }

        return $context[0];
    }

    /**
     * @return array{0: TelegramTurn, 1: string, 2: bool}|null turn, sender id, private chat
     */
    private function context(TelegramBot $bot, mixed $chatPayload, mixed $from, string $updateKey): ?array
    {
        if (!is_array($chatPayload) || !is_array($from)) {
            return null;
        }
        $tgChatId = $this->scalarId($chatPayload['id'] ?? null);
        $tgUserId = $this->scalarId($from['id'] ?? null);
        if (null === $tgChatId || null === $tgUserId) {
            return null;
        }

        $owner = $this->users->find($bot->getOwnerId());
        if (!$owner instanceof User) {
            return null;
        }
        $token = $this->connections->revealToken($bot);
        if (null === $token) {
            return null;
        }

        // Telegram only delivers after the owner unblocked the bot, so the
        // channel works again without a trip to the Channels page.
        if (
            TelegramBot::STATUS_ERROR === $bot->getStatus()
            && TelegramChannelException::BOT_BLOCKED === $bot->getErrorCode()
            && $bot->getTgUserId() === $tgUserId
        ) {
            $this->connections->recover($bot);
        }

        $turn = new TelegramTurn($bot, $owner, $token, $tgChatId, $updateKey, $this->localeFor($bot, $owner, $tgUserId, $from));

        return [$turn, $tgUserId, 'private' === (string) ($chatPayload['type'] ?? '')];
    }

    private function isOwner(TelegramBot $bot, string $tgUserId): bool
    {
        return TelegramBot::STATUS_CONNECTED === $bot->getStatus() && $bot->getTgUserId() === $tgUserId;
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
        $telegramLanguage = $this->telegramLanguage($from);
        if ($isOwner) {
            return $owner->getPreferredLanguage() ?? $telegramLanguage ?? 'en';
        }

        return $telegramLanguage ?? $owner->getLocale();
    }

    /**
     * @param array<string, mixed> $from
     */
    private function telegramLanguage(array $from): ?string
    {
        $code = $from['language_code'] ?? null;
        if (!is_string($code)) {
            return null;
        }

        $language = strtolower(substr($code, 0, 2));

        return in_array($language, self::SUPPORTED_LOCALES, true) ? $language : null;
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
