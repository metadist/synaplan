<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CustomToolRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CustomToolRepository::class)]
#[ORM\Table(name: 'BTOOLS')]
#[ORM\UniqueConstraint(name: 'uq_tools_owner_name', columns: ['BOWNERID', 'BNAME'])]
#[ORM\Index(columns: ['BCREDENTIALID'], name: 'idx_tools_credential')]
class CustomTool
{
    public const TYPE_HTTP = 'http';
    public const TYPE_OPENAPI = 'openapi_op';
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{2,63}$/';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BOWNERID', type: 'bigint')]
    private int $ownerId;

    #[ORM\Column(name: 'BNAME', length: 64)]
    private string $name;

    #[ORM\Column(name: 'BTITLE', length: 191)]
    private string $title;

    #[ORM\Column(name: 'BDESCRIPTION', type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'BTYPE', length: 16, options: ['default' => self::TYPE_HTTP])]
    private string $type = self::TYPE_HTTP;

    #[ORM\Column(name: 'BSIDEEFFECT', length: 16, options: ['default' => 'write'])]
    private string $sideEffect = 'write';

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'BSPEC', type: 'json')]
    private array $spec = [];

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'BINPUTSCHEMA', type: 'json', nullable: true)]
    private ?array $inputSchema = null;

    #[ORM\Column(name: 'BCREDENTIALID', type: 'bigint', nullable: true)]
    private ?int $credentialId = null;

    #[ORM\Column(name: 'BENABLED', type: 'boolean', options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(name: 'BSOURCEREF', length: 512, nullable: true)]
    private ?string $sourceRef = null;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    #[ORM\Column(name: 'BUPDATED', type: 'bigint')]
    private int $updated;

    public function __construct(int $ownerId, string $name, string $title)
    {
        $now = time();
        $this->ownerId = $ownerId;
        $this->name = $name;
        $this->title = $title;
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
        $this->touch();
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
        $this->touch();
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
        $this->touch();
    }

    public function getSideEffect(): string
    {
        return $this->sideEffect;
    }

    public function setSideEffect(string $sideEffect): void
    {
        $this->sideEffect = $sideEffect;
        $this->touch();
    }

    /**
     * @return array<string, mixed>
     */
    public function getSpec(): array
    {
        return $this->spec;
    }

    /**
     * @param array<string, mixed> $spec
     */
    public function setSpec(array $spec): void
    {
        $this->spec = $spec;
        $this->touch();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getInputSchema(): ?array
    {
        return $this->inputSchema;
    }

    /**
     * @param array<string, mixed>|null $inputSchema
     */
    public function setInputSchema(?array $inputSchema): void
    {
        $this->inputSchema = $inputSchema;
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

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
        $this->touch();
    }

    public function getSourceRef(): ?string
    {
        return $this->sourceRef;
    }

    public function setSourceRef(?string $sourceRef): void
    {
        $this->sourceRef = $sourceRef;
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

    public function registryName(): string
    {
        return 'custom:'.$this->name;
    }

    private function touch(): void
    {
        $this->updated = time();
    }
}
