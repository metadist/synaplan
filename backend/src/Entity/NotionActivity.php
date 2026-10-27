<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NotionActivityRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One Notion page Synaplan created or changed for a person.
 */
#[ORM\Entity(repositoryClass: NotionActivityRepository::class)]
#[ORM\Table(name: 'BNOTIONACTIVITY')]
#[ORM\Index(columns: ['BUSERID', 'BCREATED'], name: 'idx_notionactivity_user')]
class NotionActivity
{
    public const ACTION_CREATED = 'created';
    public const ACTION_UPDATED = 'updated';
    public const ACTION_FILE = 'file';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BUSERID', type: 'bigint')]
    private int $userId;

    #[ORM\Column(name: 'BACTION', length: 16)]
    private string $action;

    #[ORM\Column(name: 'BPAGEID', length: 64, nullable: true)]
    private ?string $pageId;

    #[ORM\Column(name: 'BTITLE', length: 255, options: ['default' => ''])]
    private string $title;

    #[ORM\Column(name: 'BURL', length: 1024, nullable: true)]
    private ?string $url;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    public function __construct(int $userId, string $action, ?string $pageId, string $title, ?string $url)
    {
        $this->userId = $userId;
        $this->action = $action;
        $this->pageId = $pageId;
        $this->title = mb_substr($title, 0, 255);
        $this->url = null === $url ? null : mb_substr($url, 0, 1024);
        $this->created = time();
    }

    public function getId(): ?int
    {
        return null === $this->id ? null : (int) $this->id;
    }

    public function getUserId(): int
    {
        return (int) $this->userId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getPageId(): ?string
    {
        return $this->pageId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getCreated(): int
    {
        return (int) $this->created;
    }
}
