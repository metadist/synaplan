<?php

namespace App\Entity;

use App\Repository\PromptMetaRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PromptMetaRepository::class)]
#[ORM\Table(name: 'BPROMPTMETA')]
#[ORM\Index(columns: ['BPROMPTID'], name: 'idx_promptmeta_prompt')]
#[ORM\Index(columns: ['BMETAKEY'], name: 'idx_promptmeta_key')]
class PromptMeta
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BPROMPTID', type: 'bigint')]
    private int $promptId;

    #[ORM\ManyToOne(targetEntity: Prompt::class)]
    #[ORM\JoinColumn(name: 'BPROMPTID', referencedColumnName: 'BID')]
    private ?Prompt $prompt = null;

    #[ORM\Column(name: 'BMETAKEY', length: 64)]
    private string $metaKey;

    #[ORM\Column(name: 'BMETAVALUE', type: 'text')]
    private string $metaValue;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    public function __construct()
    {
        $this->created = time();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPromptId(): int
    {
        return $this->promptId;
    }

    public function setPromptId(int $promptId): self
    {
        $this->promptId = $promptId;

        return $this;
    }

    public function getPrompt(): ?Prompt
    {
        return $this->prompt;
    }

    public function setPrompt(?Prompt $prompt): self
    {
        $this->prompt = $prompt;
        if ($prompt) {
            $this->promptId = $prompt->getId();
        }

        return $this;
    }

    public function getMetaKey(): string
    {
        return $this->metaKey;
    }

    public function setMetaKey(string $metaKey): self
    {
        $this->metaKey = $metaKey;

        return $this;
    }

    public function getMetaValue(): string
    {
        return $this->metaValue;
    }

    public function setMetaValue(string $metaValue): self
    {
        $this->metaValue = $metaValue;

        return $this;
    }

    public function getMetaValueArray(): ?array
    {
        $decoded = json_decode($this->metaValue, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function setMetaValueArray(array $value): self
    {
        $this->metaValue = json_encode($value);

        return $this;
    }

    public function getCreated(): int
    {
        return $this->created;
    }
}
