<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MessageDigestRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One searchable digest line per KEY message — the deep-memory index that
 * makes months-old messages findable again ("office rent letter to realtor
 * about the increase of payments" → message 1234).
 *
 * MariaDB is the authoritative store; each active row is mirrored into the
 * Qdrant `user_message_digests` collection (point id `dig_{userId}_{digestId}`)
 * for vector search. Rows are written out of band by the daily digest job,
 * never on the chat hot path.
 */
#[ORM\Entity(repositoryClass: MessageDigestRepository::class)]
#[ORM\Table(name: 'BMESSAGEDIGESTS')]
#[ORM\UniqueConstraint(name: 'uniq_digest_user_message', columns: ['BUSERID', 'BMESSAGEID'])]
#[ORM\Index(columns: ['BUSERID', 'BACTIVE', 'BSOURCEDATE'], name: 'idx_digest_user_active_date')]
class MessageDigest
{
    /** App-assigned ms-timestamp id (same scheme as BUSERMEMORIES). */
    #[ORM\Id]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private int $id;

    #[ORM\Column(name: 'BUSERID', type: 'integer')]
    private int $userId;

    #[ORM\Column(name: 'BCHATID', type: 'integer', options: ['default' => 0])]
    private int $chatId = 0;

    #[ORM\Column(name: 'BMESSAGEID', type: 'bigint')]
    private int $messageId;

    /** The searchable one-liner written by the digest model. */
    #[ORM\Column(name: 'BTITLE', type: 'string', length: 500)]
    private string $title = '';

    /** Channel of the source message: web / whatsapp / email / mcp / api / … */
    #[ORM\Column(name: 'BCHANNEL', type: 'string', length: 20, options: ['default' => ''])]
    private string $channel = '';

    /** Unix timestamp of the SOURCE message (recency ranking input). */
    #[ORM\Column(name: 'BSOURCEDATE', type: 'bigint', options: ['default' => 0])]
    private int $sourceDate = 0;

    #[ORM\Column(name: 'BACTIVE', type: 'boolean', options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(name: 'BCREATED', type: 'bigint', options: ['default' => 0])]
    private int $created = 0;

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): self
    {
        $this->id = $id;

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

    public function getChatId(): int
    {
        return $this->chatId;
    }

    public function setChatId(int $chatId): self
    {
        $this->chatId = $chatId;

        return $this;
    }

    public function getMessageId(): int
    {
        return $this->messageId;
    }

    public function setMessageId(int $messageId): self
    {
        $this->messageId = $messageId;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function setChannel(string $channel): self
    {
        $this->channel = $channel;

        return $this;
    }

    public function getSourceDate(): int
    {
        return $this->sourceDate;
    }

    public function setSourceDate(int $sourceDate): self
    {
        $this->sourceDate = $sourceDate;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

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
}
