<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use App\Entity\TelegramBot;
use App\Entity\User;
use App\Repository\TelegramBotRepository;
use App\Service\Credential\CredentialVaultInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Connect, pair, and disconnect one bot per user. The token is stored in
 * the credential vault and is never returned to the client.
 */
final readonly class TelegramConnectionService
{
    public const PAIR_CODE_TTL_SECONDS = 1800;
    /** Bumped when the "/" menu content changes; stale bots re-register on inbound. */
    public const COMMAND_MENU_VERSION = 2;
    /** Only /help appears in Telegram's "/" menu; typed commands stay in /help text. */
    private const COMMANDS = ['help'];
    /** English is also the menu for every language without its own. */
    private const DEFAULT_COMMAND_LOCALE = 'en';
    private const COMMAND_LOCALES = ['en', 'de', 'es', 'fr', 'tr'];
    /** Covers the vault write, setWebhook and the menu calls of one connect. */
    private const CONNECT_LOCK_SECONDS = 60.0;

    public function __construct(
        private TelegramBotRepository $bots,
        private TelegramBotApi $api,
        private PublicWebhookUrlValidator $urls,
        private CredentialVaultInterface $vault,
        private LoggerInterface $logger,
        private TelegramCopy $copy,
        private LockFactory $locks,
        private string $appUrl,
        private string $webhookBaseUrl = '',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function status(User $user): array
    {
        return $this->present($this->bots->findOneByOwner((int) $user->getId()));
    }

    /**
     * @return array<string, mixed>
     */
    public function connect(User $user, string $token): array
    {
        $token = trim($token);
        $base = '' !== trim($this->webhookBaseUrl) ? trim($this->webhookBaseUrl) : trim($this->appUrl);
        $this->urls->assertPublic($base);
        if (!preg_match('/^\d+:[A-Za-z0-9_-]{10,}$/', $token)) {
            throw new TelegramChannelException(TelegramChannelException::TOKEN_INVALID);
        }

        $identity = $this->api->getMe($token);
        // BBOTID is not unique (old rows keep it), so the check and the webhook switch run under one lock per bot.
        $lock = $this->locks->createLock('telegram_connect_'.$identity->id, self::CONNECT_LOCK_SECONDS);
        $lock->acquire(true);
        try {
            return $this->connectLocked($user, $token, $identity, $base);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function connectLocked(User $user, string $token, TelegramBotIdentity $identity, string $base): array
    {
        $ownerId = (int) $user->getId();
        if (null !== $this->bots->findActiveByBotIdForOtherOwner($identity->id, $ownerId)) {
            throw new TelegramChannelException(TelegramChannelException::BOT_IN_USE);
        }
        $existing = $this->bots->findOneByOwner($ownerId);
        if (null !== $existing) {
            $this->dropRemoteWebhook($existing);
            $this->forgetCredential($existing);
        }

        $credentialId = $this->vault->store($ownerId, TelegramBot::CREDENTIAL_KIND, $token);
        $botKey = $existing?->getBotKey() ?? bin2hex(random_bytes(16));
        $secret = bin2hex(random_bytes(32));

        try {
            $this->api->setWebhook($token, $this->webhookUrl($base, $botKey), $secret);
        } catch (TelegramChannelException $e) {
            $this->forgetId($credentialId, $ownerId);
            if (null !== $existing) {
                $existing->setCredentialId(null);
                $existing->setSecretHash('');
                $existing->setStatus(TelegramBot::STATUS_ERROR);
                $existing->setErrorCode($e->errorCode);
                $this->bots->save($existing);
            }
            throw $e;
        }

        $bot = $existing ?? new TelegramBot($ownerId, $botKey, $identity->id, $identity->username);
        if (null !== $existing) {
            if ($existing->getBotId() !== $identity->id) {
                // A different bot starts a new thread; the old one stays in History under its own title.
                $bot->setChatId(null);
                $bot->setLastMessageAt(null);
            }
            $bot->setBotId($identity->id);
            $bot->setBotUsername($identity->username);
        }
        $bot->setCredentialId($credentialId);
        $bot->setSecretHash(hash('sha256', $secret));
        $this->issuePairCode($bot);
        $bot->setTgUserId(null);
        $bot->setTgChatId(null);
        $bot->setStatus(TelegramBot::STATUS_PENDING);
        $bot->setErrorCode(null);
        $this->bots->save($bot);
        $this->registerCommands($token);
        $bot->setCommandsMenuVersion(self::COMMAND_MENU_VERSION);
        $this->bots->save($bot);

        return $this->present($bot);
    }

    /**
     * Points the webhook of a working bot at the current URL with the
     * current update types and menu. Returns false when Telegram refused.
     */
    public function refresh(TelegramBot $bot): bool
    {
        $token = $this->revealToken($bot);
        if (null === $token || !in_array($bot->getStatus(), [TelegramBot::STATUS_PENDING, TelegramBot::STATUS_CONNECTED], true)) {
            return false;
        }
        $base = '' !== trim($this->webhookBaseUrl) ? trim($this->webhookBaseUrl) : trim($this->appUrl);
        $secret = bin2hex(random_bytes(32));
        try {
            $this->urls->assertPublic($base);
            $this->api->setWebhook($token, $this->webhookUrl($base, $bot->getBotKey()), $secret);
        } catch (TelegramChannelException $e) {
            $this->logger->warning('Telegram webhook refresh failed', [
                'bot_id' => $bot->getId(),
                'error' => $e->errorCode,
            ]);

            return false;
        }
        $bot->setSecretHash(hash('sha256', $secret));
        $this->bots->save($bot);
        $this->registerCommands($token);
        $bot->setCommandsMenuVersion(self::COMMAND_MENU_VERSION);
        $this->bots->save($bot);

        return true;
    }

    /**
     * Re-register the "/" menu when the stored version is behind
     * {@see COMMAND_MENU_VERSION}. Best effort — never blocks the inbound turn.
     */
    public function ensureCommandMenu(TelegramBot $bot): void
    {
        if (self::COMMAND_MENU_VERSION === $bot->getCommandsMenuVersion()) {
            return;
        }
        $token = $this->revealToken($bot);
        if (null === $token) {
            return;
        }
        $this->registerCommands($token);
        $bot->setCommandsMenuVersion(self::COMMAND_MENU_VERSION);
        $this->bots->save($bot);
    }

    /**
     * The "/" menu in Telegram, in every language we support. Best effort:
     * the bot answers commands without the menu too. A failing locale is
     * logged and the remaining locales are still attempted.
     */
    public function registerCommands(string $token): void
    {
        foreach (self::COMMAND_LOCALES as $locale) {
            $commands = [];
            foreach (self::COMMANDS as $command) {
                $commands[] = ['command' => $command, 'description' => $this->copy->say($locale, 'command_'.$command)];
            }
            try {
                $this->api->setMyCommands($token, $commands, self::DEFAULT_COMMAND_LOCALE === $locale ? null : $locale);
            } catch (TelegramChannelException $e) {
                $this->logger->info('Telegram setMyCommands skipped', [
                    'locale' => $locale,
                    'error' => $e->errorCode,
                ]);
            }
        }
    }

    public function pair(TelegramBot $bot, string $code, string $tgUserId, string $tgChatId): TelegramPairResult
    {
        if (TelegramBot::STATUS_PENDING !== $bot->getStatus()) {
            return TelegramPairResult::Mismatch;
        }
        $expected = $bot->getPairCode() ?? '';
        if ('' === $expected || !hash_equals($expected, $code)) {
            return TelegramPairResult::Mismatch;
        }
        if ($bot->isPairCodeExpired(time())) {
            return TelegramPairResult::Expired;
        }

        $bot->setTgUserId($tgUserId);
        $bot->setTgChatId($tgChatId);
        $bot->setStatus(TelegramBot::STATUS_CONNECTED);
        $bot->setPairCode(null);
        $bot->setPairCodeExpires(null);
        $bot->setErrorCode(null);
        $this->bots->save($bot);

        return TelegramPairResult::Paired;
    }

    /**
     * A fresh pairing link for a bot that is still waiting, without asking
     * for the token again.
     *
     * @return array<string, mixed>
     */
    public function renewPairing(User $user): array
    {
        $bot = $this->bots->findOneByOwner((int) $user->getId());
        if (null !== $bot && TelegramBot::STATUS_PENDING === $bot->getStatus()) {
            $this->issuePairCode($bot);
            $this->bots->save($bot);
        }

        return $this->present($bot);
    }

    /**
     * The paired owner wrote again after blocking the bot: Telegram delivers
     * again, so the channel works again.
     */
    public function recover(TelegramBot $bot): void
    {
        $bot->setStatus(TelegramBot::STATUS_CONNECTED);
        $bot->setErrorCode(null);
        $this->bots->save($bot);
    }

    public function noteExchange(TelegramBot $bot): void
    {
        $bot->setLastMessageAt(time());
        $this->bots->save($bot);
    }

    public function attachChat(TelegramBot $bot, int $chatId): void
    {
        $bot->setChatId($chatId);
        $this->bots->save($bot);
    }

    /**
     * @return array<string, mixed>
     */
    public function disconnect(User $user): array
    {
        $bot = $this->bots->findOneByOwner((int) $user->getId());
        if (null === $bot || TelegramBot::STATUS_DISCONNECTED === $bot->getStatus()) {
            return $this->present($bot);
        }

        $this->dropRemoteWebhook($bot);
        $this->forgetCredential($bot);
        $bot->setCredentialId(null);
        $bot->setSecretHash('');
        $bot->setPairCode(null);
        $bot->setPairCodeExpires(null);
        $bot->setStatus(TelegramBot::STATUS_DISCONNECTED);
        $bot->setErrorCode(null);
        $this->bots->save($bot);

        return $this->present($bot);
    }

    /**
     * Account deletion: removes the row and the stored token without a flush,
     * so it commits with the rest of the account. Returns the token so the
     * caller can drop the remote webhook once the commit succeeded.
     */
    public function removeForOwner(int $ownerId): ?string
    {
        $bot = $this->bots->findOneByOwner($ownerId);
        if (null === $bot) {
            return null;
        }
        $token = $this->revealToken($bot);
        $this->forgetCredential($bot);
        $this->bots->remove($bot, false);

        return $token;
    }

    /**
     * Best effort: the bot row is already gone, so a failure only leaves a
     * webhook Telegram gives up on after its retries.
     */
    public function dropWebhookForToken(string $token): void
    {
        try {
            $this->api->deleteWebhook($token);
        } catch (TelegramChannelException $e) {
            $this->logger->info('Telegram deleteWebhook after account deletion skipped', [
                'error' => $e->errorCode,
            ]);
        }
    }

    public function revealToken(TelegramBot $bot): ?string
    {
        $credentialId = $bot->getCredentialId();
        if (null === $credentialId) {
            return null;
        }

        try {
            return $this->vault->reveal($credentialId, $bot->getOwnerId());
        } catch (\Throwable $e) {
            $this->logger->warning('Telegram bot token could not be read', [
                'bot_id' => $bot->getId(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function markError(TelegramBot $bot, string $errorCode): void
    {
        $bot->setStatus(TelegramBot::STATUS_ERROR);
        $bot->setErrorCode($errorCode);
        $this->bots->save($bot);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(?TelegramBot $bot): array
    {
        if (null === $bot) {
            return [
                'success' => true,
                'status' => 'none',
                'botUsername' => null,
                'pairingLink' => null,
                'pairingExpiresAt' => null,
                'chatId' => null,
                'lastMessageAt' => null,
                'errorCode' => null,
            ];
        }

        $pending = TelegramBot::STATUS_PENDING === $bot->getStatus();

        return [
            'success' => true,
            'status' => $bot->getStatus(),
            'botUsername' => $bot->getBotUsername(),
            'pairingLink' => $this->pairingLink($bot),
            'pairingExpiresAt' => $pending ? $bot->getPairCodeExpires() : null,
            'chatId' => $bot->getChatId(),
            'lastMessageAt' => $bot->getLastMessageAt(),
            'errorCode' => $bot->getErrorCode(),
        ];
    }

    private function pairingLink(TelegramBot $bot): ?string
    {
        if (TelegramBot::STATUS_PENDING !== $bot->getStatus() || $bot->isPairCodeExpired(time())) {
            return null;
        }
        $code = $bot->getPairCode();
        $username = $bot->getBotUsername();
        if (null === $code || '' === $code || '' === $username) {
            return null;
        }

        return 'https://t.me/'.$username.'?start='.rawurlencode($code);
    }

    private function dropRemoteWebhook(TelegramBot $bot): void
    {
        $token = $this->revealToken($bot);
        if (null === $token) {
            return;
        }

        try {
            $this->api->deleteWebhook($token);
        } catch (TelegramChannelException $e) {
            $this->logger->info('Telegram deleteWebhook skipped', [
                'bot_id' => $bot->getId(),
                'error' => $e->errorCode,
            ]);
        }
    }

    private function forgetCredential(TelegramBot $bot): void
    {
        $credentialId = $bot->getCredentialId();
        if (null === $credentialId) {
            return;
        }
        $this->forgetId($credentialId, $bot->getOwnerId());
        $bot->setCredentialId(null);
    }

    private function forgetId(int $credentialId, int $ownerId): void
    {
        try {
            $this->vault->forget($credentialId, $ownerId);
        } catch (\Throwable $e) {
            $this->logger->info('Telegram credential forget skipped', [
                'credential_id' => $credentialId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function webhookUrl(string $base, string $botKey): string
    {
        return rtrim($base, '/').'/api/v1/webhooks/telegram/'.$botKey;
    }

    private function issuePairCode(TelegramBot $bot): void
    {
        $bot->setPairCode($this->newPairCode());
        $bot->setPairCodeExpires(time() + self::PAIR_CODE_TTL_SECONDS);
    }

    private function newPairCode(): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $max = strlen($alphabet) - 1;
        $code = '';
        for ($i = 0; $i < 8; ++$i) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }
}
