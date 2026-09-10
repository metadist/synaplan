<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UrlWatchRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UrlWatchRepository::class)]
#[ORM\Table(name: 'BURLWATCHES')]
#[ORM\UniqueConstraint(name: 'uq_urlwatch_owner_hash', columns: ['BOWNERID', 'BURLHASH'])]
#[ORM\Index(columns: ['BOWNERID'], name: 'idx_urlwatch_owner')]
class UrlWatch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BOWNERID', type: 'bigint')]
    private int $ownerId;

    #[ORM\Column(name: 'BURL', length: 2048)]
    private string $url;

    #[ORM\Column(name: 'BURLHASH', length: 64)]
    private string $urlHash;

    #[ORM\Column(name: 'BTITLE', length: 512, options: ['default' => ''])]
    private string $title = '';

    #[ORM\Column(name: 'BTEXT', type: 'text', nullable: true)]
    private ?string $body = null;

    #[ORM\Column(name: 'BCONTENTHASH', length: 64, nullable: true)]
    private ?string $contentHash = null;

    #[ORM\Column(name: 'BFETCHEDAT', type: 'bigint', nullable: true)]
    private ?int $fetchedAt = null;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    #[ORM\Column(name: 'BUPDATED', type: 'bigint')]
    private int $updated;

    #[ORM\Column(name: 'BLASTDIFFTEXT', type: 'text', nullable: true)]
    private ?string $lastDiffText = null;

    #[ORM\Column(name: 'BLASTERROR', length: 512, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(name: 'BLASTFAILEDAT', type: 'bigint', nullable: true)]
    private ?int $lastFailedAt = null;

    public function __construct(int $ownerId, string $url, string $urlHash)
    {
        $now = time();
        $this->ownerId = $ownerId;
        $this->url = $url;
        $this->urlHash = $urlHash;
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

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getUrlHash(): string
    {
        return $this->urlHash;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function getContentHash(): ?string
    {
        return $this->contentHash;
    }

    public function getFetchedAt(): ?int
    {
        return $this->fetchedAt;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function getUpdated(): int
    {
        return $this->updated;
    }

    public function getLastDiffText(): ?string
    {
        return $this->lastDiffText;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getLastFailedAt(): ?int
    {
        return $this->lastFailedAt;
    }

    public function setLastDiffText(?string $diff): void
    {
        $this->lastDiffText = (null === $diff || '' === $diff) ? null : $diff;
    }

    public function recordFailure(string $error): void
    {
        $this->lastError = mb_substr($error, 0, 512);
        $this->lastFailedAt = time();
        $this->updated = $this->lastFailedAt;
    }

    public function replaceSnapshot(string $title, string $body, string $contentHash, int $fetchedAt): void
    {
        if ('' !== $title) {
            $this->title = mb_substr($title, 0, 512);
        }
        $this->body = $body;
        $this->contentHash = $contentHash;
        $this->fetchedAt = $fetchedAt;
        $this->updated = $fetchedAt;
        $this->lastError = null;
        $this->lastFailedAt = null;
    }
}
