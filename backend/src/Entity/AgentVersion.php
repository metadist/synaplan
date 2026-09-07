<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AgentVersionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Immutable published snapshot of an assistant. Application code never
 * updates or deletes a row except when the parent agent is removed.
 */
#[ORM\Entity(repositoryClass: AgentVersionRepository::class)]
#[ORM\Table(name: 'BAGENTVERSIONS')]
#[ORM\UniqueConstraint(name: 'uq_agentversions_agent_version', columns: ['BAGENTID', 'BVERSION'])]
class AgentVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BAGENTID', type: 'bigint')]
    private int $agentId;

    #[ORM\Column(name: 'BVERSION', type: 'integer')]
    private int $version;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'BDEFINITION', type: 'json')]
    private array $definition;

    #[ORM\Column(name: 'BPROMPTTEXT', type: 'text', length: 16777215)]
    private string $promptText;

    #[ORM\Column(name: 'BCHANGELOG', type: 'text', length: 65535, nullable: true)]
    private ?string $changelog = null;

    #[ORM\Column(name: 'BPUBLISHEDBY', type: 'bigint')]
    private int $publishedBy;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    /**
     * @param array<string, mixed> $definition
     */
    public function __construct(
        int $agentId,
        int $version,
        array $definition,
        string $promptText,
        int $publishedBy,
        ?string $changelog = null,
    ) {
        $this->agentId = $agentId;
        $this->version = $version;
        $this->definition = $definition;
        $this->promptText = $promptText;
        $this->publishedBy = $publishedBy;
        $this->changelog = $changelog;
        $this->created = time();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAgentId(): int
    {
        return $this->agentId;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefinition(): array
    {
        return $this->definition;
    }

    public function getPromptText(): string
    {
        return $this->promptText;
    }

    public function getChangelog(): ?string
    {
        return $this->changelog;
    }

    public function getPublishedBy(): int
    {
        return $this->publishedBy;
    }

    public function getCreated(): int
    {
        return $this->created;
    }
}
