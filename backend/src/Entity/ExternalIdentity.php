<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ExternalIdentityRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Links a Synaplan user to an identity on another system (OIDC, Nextcloud, …).
 *
 * Unique on (source, instanceId, externalId). BUSERDETAILS JSON remains as a
 * read fallback until every writer has been migrated.
 */
#[ORM\Entity(repositoryClass: ExternalIdentityRepository::class)]
#[ORM\Table(name: 'BEXTERNALIDENTITIES')]
#[ORM\UniqueConstraint(name: 'uniq_extid_source', columns: ['BSOURCE', 'BINSTANCEID', 'BEXTERNALID'])]
#[ORM\Index(columns: ['BUSERID'], name: 'idx_extid_user')]
class ExternalIdentity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BUSERID', type: 'bigint')]
    private int $userId;

    #[ORM\Column(name: 'BSOURCE', length: 191)]
    private string $source = '';

    #[ORM\Column(name: 'BINSTANCEID', length: 191, options: ['default' => ''])]
    private string $instanceId = '';

    #[ORM\Column(name: 'BEXTERNALID', length: 191)]
    private string $externalId = '';

    #[ORM\Column(name: 'BAPIKEYID', type: 'bigint', nullable: true)]
    private ?int $apiKeyId = null;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    #[ORM\Column(name: 'BLASTSEEN', type: 'bigint', options: ['default' => 0])]
    private int $lastSeen = 0;

    public function __construct()
    {
        $this->created = time();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function getInstanceId(): string
    {
        return $this->instanceId;
    }

    public function setInstanceId(string $instanceId): self
    {
        $this->instanceId = $instanceId;

        return $this;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function setExternalId(string $externalId): self
    {
        $this->externalId = $externalId;

        return $this;
    }

    public function getApiKeyId(): ?int
    {
        return $this->apiKeyId;
    }

    public function setApiKeyId(?int $apiKeyId): self
    {
        $this->apiKeyId = $apiKeyId;

        return $this;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function getLastSeen(): int
    {
        return $this->lastSeen;
    }

    public function setLastSeen(int $lastSeen): self
    {
        $this->lastSeen = $lastSeen;

        return $this;
    }

    public function touchLastSeen(): self
    {
        $this->lastSeen = time();

        return $this;
    }

    /**
     * Short badge label for the People page (OIDC / Nextcloud / …).
     */
    public function badge(): string
    {
        if (str_starts_with($this->source, 'oidc:')) {
            return 'OIDC';
        }

        return match (strtolower($this->source)) {
            'nextcloud' => 'Nextcloud',
            'owncloud' => 'ownCloud',
            'opencloud' => 'OpenCloud',
            'outlook' => 'Outlook',
            default => $this->source,
        };
    }
}
