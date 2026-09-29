<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TelegramBotRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One Telegram bot connected by a user. The bot token is not stored here;
 * {@see BCREDENTIALID} points at an encrypted row in BCREDENTIALS.
 */
#[ORM\Entity(repositoryClass: TelegramBotRepository::class)]
#[ORM\Table(name: 'BTELEGRAMBOT')]
#[ORM\UniqueConstraint(name: 'uq_telegrambot_owner', columns: ['BOWNERID'])]
#[ORM\UniqueConstraint(name: 'uq_telegrambot_key', columns: ['BBOTKEY'])]
class TelegramBot
{
    public const STATUS_PENDING = 'pending_pairing';
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_ERROR = 'error';
    public const STATUS_DISCONNECTED = 'disconnected';

    public const CREDENTIAL_KIND = 'telegram_bot';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BOWNERID', type: 'bigint')]
    private int $ownerId;

    #[ORM\Column(name: 'BBOTKEY', length: 64)]
    private string $botKey;

    #[ORM\Column(name: 'BBOTID', type: 'bigint')]
    private int $botId;

    #[ORM\Column(name: 'BBOTUSERNAME', length: 64)]
    private string $botUsername;

    #[ORM\Column(name: 'BCREDENTIALID', type: 'bigint', nullable: true)]
    private ?int $credentialId = null;

    #[ORM\Column(name: 'BSECRETHASH', length: 64)]
    private string $secretHash = '';

    #[ORM\Column(name: 'BPAIRCODE', length: 16, nullable: true)]
    private ?string $pairCode = null;

    #[ORM\Column(name: 'BPAIRCODEHASH', length: 64, nullable: true)]
    private ?string $pairCodeHash = null;

    #[ORM\Column(name: 'BTGUSERID', length: 32, nullable: true)]
    private ?string $tgUserId = null;

    #[ORM\Column(name: 'BTGCHATID', length: 32, nullable: true)]
    private ?string $tgChatId = null;

    #[ORM\Column(name: 'BCHATID', type: 'integer', nullable: true)]
    private ?int $chatId = null;

    #[ORM\Column(name: 'BSTATUS', length: 24)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'BERRORCODE', length: 64, nullable: true)]
    private ?string $errorCode = null;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    #[ORM\Column(name: 'BUPDATED', type: 'bigint')]
    private int $updated;

    public function __construct(int $ownerId, string $botKey, int $botId, string $botUsername)
    {
        $now = time();
        $this->ownerId = $ownerId;
        $this->botKey = $botKey;
        $this->botId = $botId;
        $this->botUsername = $botUsername;
        $this->created = $now;
        $this->updated = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwnerId(): int
    {
        return $this->ownerId;
    }

    public function getBotKey(): string
    {
        return $this->botKey;
    }

    public function getBotId(): int
    {
        return $this->botId;
    }

    public function setBotId(int $botId): void
    {
        $this->botId = $botId;
        $this->touch();
    }

    public function getBotUsername(): string
    {
        return $this->botUsername;
    }

    public function setBotUsername(string $botUsername): void
    {
        $this->botUsername = $botUsername;
        $this->touch();
    }

    public function getCredentialId(): ?int
    {
        return $this->credentialId;
    }

    public function setCredentialId(?int $credentialId): void
    {
        $this->credentialId = $credentialId;
        $this->touch();
    }

    public function getSecretHash(): string
    {
        return $this->secretHash;
    }

    public function setSecretHash(string $secretHash): void
    {
        $this->secretHash = $secretHash;
        $this->touch();
    }

    public function getPairCode(): ?string
    {
        return $this->pairCode;
    }

    public function setPairCode(?string $pairCode): void
    {
        $this->pairCode = $pairCode;
        $this->touch();
    }

    public function getPairCodeHash(): ?string
    {
        return $this->pairCodeHash;
    }

    public function setPairCodeHash(?string $pairCodeHash): void
    {
        $this->pairCodeHash = $pairCodeHash;
        $this->touch();
    }

    public function getTgUserId(): ?string
    {
        return $this->tgUserId;
    }

    public function setTgUserId(?string $tgUserId): void
    {
        $this->tgUserId = $tgUserId;
        $this->touch();
    }

    public function getTgChatId(): ?string
    {
        return $this->tgChatId;
    }

    public function setTgChatId(?string $tgChatId): void
    {
        $this->tgChatId = $tgChatId;
        $this->touch();
    }

    public function getChatId(): ?int
    {
        return $this->chatId;
    }

    public function setChatId(?int $chatId): void
    {
        $this->chatId = $chatId;
        $this->touch();
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
        $this->touch();
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function setErrorCode(?string $errorCode): void
    {
        $this->errorCode = $errorCode;
        $this->touch();
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function getUpdated(): int
    {
        return $this->updated;
    }

    public function touch(): void
    {
        $this->updated = time();
    }
}
