<?php

namespace App\Entity;

use App\Repository\SearchResultRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entity to store web search results for messages
 */
#[ORM\Entity(repositoryClass: SearchResultRepository::class)]
#[ORM\Table(name: 'BSEARCHRESULTS')]
#[ORM\Index(columns: ['BMESSAGEID'], name: 'idx_message')]
#[ORM\Index(columns: ['BQUERY'], name: 'idx_query')]
class SearchResult
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Message::class)]
    #[ORM\JoinColumn(name: 'BMESSAGEID', referencedColumnName: 'BID', nullable: false, onDelete: 'CASCADE')]
    private Message $message;

    #[ORM\Column(name: 'BQUERY', type: 'string', length: 500)]
    private string $query;

    #[ORM\Column(name: 'BTITLE', type: 'string', length: 500)]
    private string $title;

    #[ORM\Column(name: 'BURL', type: 'text')]
    private string $url;

    #[ORM\Column(name: 'BDESCRIPTION', type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'BPUBLISHED', type: 'string', length: 100, nullable: true)]
    private ?string $published = null;

    #[ORM\Column(name: 'BSOURCE', type: 'string', length: 255, nullable: true)]
    private ?string $source = null;

    #[ORM\Column(name: 'BTHUMBNAIL', type: 'text', nullable: true)]
    private ?string $thumbnail = null;

    #[ORM\Column(name: 'BPOSITION', type: 'integer')]
    private int $position = 0;

    #[ORM\Column(name: 'BEXTRASNIPPETS', type: 'json', nullable: true)]
    private ?array $extraSnippets = null;

    #[ORM\Column(name: 'BCREATEDAT', type: 'datetime')]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMessage(): Message
    {
        return $this->message;
    }

    public function setMessage(Message $message): self
    {
        $this->message = $message;
        return $this;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function setQuery(string $query): self
    {
        $this->query = $query;
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

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): self
    {
        $this->url = $url;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getPublished(): ?string
    {
        return $this->published;
    }

    public function setPublished(?string $published): self
    {
        $this->published = $published;
        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(?string $source): self
    {
        $this->source = $source;
        return $this;
    }

    public function getThumbnail(): ?string
    {
        return $this->thumbnail;
    }

    public function setThumbnail(?string $thumbnail): self
    {
        $this->thumbnail = $thumbnail;
        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;
        return $this;
    }

    public function getExtraSnippets(): ?array
    {
        return $this->extraSnippets;
    }

    public function setExtraSnippets(?array $extraSnippets): self
    {
        $this->extraSnippets = $extraSnippets;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    /**
     * Convert to array for JSON serialization
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'query' => $this->query,
            'title' => $this->title,
            'url' => $this->url,
            'description' => $this->description,
            'published' => $this->published,
            'source' => $this->source,
            'thumbnail' => $this->thumbnail,
            'position' => $this->position,
            'extra_snippets' => $this->extraSnippets,
        ];
    }
}

