<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GuestSessionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GuestSessionRepository::class)]
#[ORM\Table(name: 'BGUEST_SESSIONS')]
#[ORM\Index(columns: ['BSESSIONID'], name: 'idx_guest_session_id')]
#[ORM\Index(columns: ['BEXPIRES'], name: 'idx_guest_expires')]
class GuestSession
{
    public const DEFAULT_MAX_MESSAGES = 5;
    public const SESSION_EXPIRY_HOURS = 24;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BSESSIONID', length: 64, unique: true)]
    private string $sessionId;

    #[ORM\Column(name: 'BMESSAGECOUNT', type: 'integer')]
    private int $messageCount = 0;

    #[ORM\Column(name: 'BMAXMESSAGES', type: 'integer')]
    private int $maxMessages = self::DEFAULT_MAX_MESSAGES;

    #[ORM\Column(name: 'BCHATID', type: 'bigint', nullable: true)]
    private ?int $chatId = null;

    #[ORM\Column(name: 'BIPADDRESS', length: 45, nullable: true)]
    private ?string $ipAddress = null;

    /**
     * ISO 3166-1 Alpha-2 country code from Cloudflare geolocation.
     * Null if not detected or if using Tor (T1) or unknown (XX).
     */
    #[ORM\Column(name: 'BCOUNTRY', type: 'string', length: 2, nullable: true)]
    private ?string $country = null;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    #[ORM\Column(name: 'BEXPIRES', type: 'bigint')]
    private int $expires;

    public function __construct()
    {
        $this->created = time();
        $this->expires = time() + (self::SESSION_EXPIRY_HOURS * 3600);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function setSessionId(string $sessionId): self
    {
        $this->sessionId = $sessionId;

        return $this;
    }

    public function getMessageCount(): int
    {
        return $this->messageCount;
    }

    public function setMessageCount(int $messageCount): self
    {
        $this->messageCount = $messageCount;

        return $this;
    }

    public function incrementMessageCount(): self
    {
        ++$this->messageCount;

        return $this;
    }

    public function getMaxMessages(): int
    {
        return $this->maxMessages;
    }

    public function setMaxMessages(int $maxMessages): self
    {
        $this->maxMessages = $maxMessages;

        return $this;
    }

    public function getChatId(): ?int
    {
        return $this->chatId;
    }

    public function setChatId(?int $chatId): self
    {
        $this->chatId = $chatId;

        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): self
    {
        $this->ipAddress = $ipAddress;

        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): self
    {
        if ('XX' === $country || 'T1' === $country || null === $country || '' === $country) {
            $this->country = null;
        } else {
            $this->country = strtoupper($country);
        }

        return $this;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function setCreated(int $created): self
    {
        $this->created = $created;

        return $this;
    }

    public function getExpires(): int
    {
        return $this->expires;
    }

    public function setExpires(int $expires): self
    {
        $this->expires = $expires;

        return $this;
    }

    public function isExpired(): bool
    {
        return time() > $this->expires;
    }

    public function getRemainingMessages(): int
    {
        return max(0, $this->maxMessages - $this->messageCount);
    }

    public function isLimitReached(): bool
    {
        return $this->messageCount >= $this->maxMessages;
    }
}
