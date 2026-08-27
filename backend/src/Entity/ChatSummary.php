<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ChatSummaryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Durable store for the rolling conversation summary (one row per chat).
 *
 * Redis remains the hot cache in front of this table; the row is what keeps
 * continuity alive for slow channels (email, WhatsApp) whose turns usually
 * arrive long after the cache TTL expired.
 *
 * BFINGERPRINT captures the config knobs that shape the summary — a row
 * written under different settings is treated as absent so the worker
 * re-bootstraps, mirroring the cache-key fingerprint behaviour.
 */
#[ORM\Entity(repositoryClass: ChatSummaryRepository::class)]
#[ORM\Table(name: 'BCHATSUMMARIES')]
#[ORM\UniqueConstraint(name: 'uniq_chatsummary_chat', columns: ['BCHATID'])]
#[ORM\Index(columns: ['BUSERID'], name: 'idx_chatsummary_user')]
class ChatSummary
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'BCHATID', type: 'integer')]
    private int $chatId;

    #[ORM\Column(name: 'BUSERID', type: 'integer')]
    private int $userId;

    #[ORM\Column(name: 'BSUMMARY', type: 'text')]
    private string $summary = '';

    /** High-water mark: id of the newest message covered by the summary (BMESSAGES.BID is bigint). */
    #[ORM\Column(name: 'BUPTOMESSAGEID', type: 'bigint')]
    private int $upToMessageId = 0;

    #[ORM\Column(name: 'BSUMMARIZEDCOUNT', type: 'integer')]
    private int $summarizedCount = 0;

    #[ORM\Column(name: 'BFINGERPRINT', type: 'string', length: 32)]
    private string $fingerprint = '';

    #[ORM\Column(name: 'BUPDATED', type: 'integer')]
    private int $updated = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChatId(): int
    {
        return $this->chatId;
    }

    public function setChatId(int $chatId): self
    {
        $this->chatId = $chatId;

        return $this;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function setUserId(int $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function setSummary(string $summary): self
    {
        $this->summary = $summary;

        return $this;
    }

    public function getUpToMessageId(): int
    {
        return $this->upToMessageId;
    }

    public function setUpToMessageId(int $upToMessageId): self
    {
        $this->upToMessageId = $upToMessageId;

        return $this;
    }

    public function getSummarizedCount(): int
    {
        return $this->summarizedCount;
    }

    public function setSummarizedCount(int $summarizedCount): self
    {
        $this->summarizedCount = $summarizedCount;

        return $this;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function setFingerprint(string $fingerprint): self
    {
        $this->fingerprint = $fingerprint;

        return $this;
    }

    public function getUpdated(): int
    {
        return $this->updated;
    }

    public function setUpdated(int $updated): self
    {
        $this->updated = $updated;

        return $this;
    }
}
