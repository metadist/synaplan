<?php

namespace App\Entity;

use App\Repository\PromptRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PromptRepository::class)]
#[ORM\Table(name: 'BPROMPTS')]
#[ORM\Index(columns: ['BTOPIC'], name: 'BTOPIC')]
class Prompt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BOWNERID', type: 'bigint')]
    private int $ownerId = 0;

    #[ORM\Column(name: 'BLANG', length: 2, options: ['default' => 'en'])]
    private string $language = 'en';

    #[ORM\Column(name: 'BTOPIC', length: 64)]
    private string $topic;

    #[ORM\Column(name: 'BSHORTDESC', type: 'text')]
    private string $shortDescription = '';

    #[ORM\Column(name: 'BPROMPT', type: 'text')]
    private string $prompt;

    #[ORM\Column(name: 'BSELECTION_RULES', type: 'text', nullable: true)]
    private ?string $selectionRules = null;

    /**
     * Comma- or newline-separated keywords/synonyms.
     *
     * Used together with `shortDescription` when the topic is embedded into
     * the synapse_topics Qdrant collection. Adding domain-specific synonyms
     * here improves recall for the SynapseRouter without bloating the
     * AI fallback sort prompt.
     */
    #[ORM\Column(name: 'BKEYWORDS', type: 'text', nullable: true)]
    private ?string $keywords = null;

    /**
     * Soft-disable flag.
     *
     * When false, the topic is hidden from the routing pool (both Synapse
     * embedding search and the AI fallback DYNAMICLIST). Existing messages
     * that reference the topic remain unaffected.
     */
    #[ORM\Column(name: 'BENABLED', type: 'boolean', options: ['default' => true])]
    private bool $enabled = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwnerId(): int
    {
        return $this->ownerId;
    }

    public function setOwnerId(int $ownerId): self
    {
        $this->ownerId = $ownerId;

        return $this;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function setLanguage(string $language): self
    {
        $this->language = $language;

        return $this;
    }

    public function getTopic(): string
    {
        return $this->topic;
    }

    public function setTopic(string $topic): self
    {
        $this->topic = $topic;

        return $this;
    }

    public function getShortDescription(): string
    {
        return $this->shortDescription;
    }

    public function setShortDescription(string $shortDescription): self
    {
        $this->shortDescription = $shortDescription;

        return $this;
    }

    public function getPrompt(): string
    {
        return $this->prompt;
    }

    public function setPrompt(string $prompt): self
    {
        $this->prompt = $prompt;

        return $this;
    }

    public function getSelectionRules(): ?string
    {
        return $this->selectionRules;
    }

    public function setSelectionRules(?string $selectionRules): self
    {
        $this->selectionRules = $selectionRules;

        return $this;
    }

    public function getKeywords(): ?string
    {
        return $this->keywords;
    }

    public function setKeywords(?string $keywords): self
    {
        $this->keywords = $keywords;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }
}
