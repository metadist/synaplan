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
    public const MODE_SSO = 'sso';

    public const MODE_LINK = 'link';

    public const MODE_PROVISION = 'provision';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_REVOKED = 'revoked';

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

    /** sso, link, provision — or null for an oidc: login row. */
    #[ORM\Column(name: 'BMODE', length: 16, nullable: true)]
    private ?string $mode = null;

    #[ORM\Column(name: 'BSTATUS', length: 16, options: ['default' => 'active'])]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(name: 'BREVOKEDAT', type: 'bigint', nullable: true)]
    private ?int $revokedAt = null;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    #[ORM\Column(name: 'BLASTSEEN', type: 'bigint', options: ['default' => 0])]
    private int $lastSeen = 0;

    /** JSON mirror of what this person lets Synaplan read in the cloud. */
    #[ORM\Column(name: 'BREADSCOPE', type: 'text', nullable: true)]
    private ?string $readScope = null;

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

    public function getMode(): ?string
    {
        return $this->mode;
    }

    public function setMode(?string $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getRevokedAt(): ?int
    {
        return $this->revokedAt;
    }

    public function setRevokedAt(?int $revokedAt): self
    {
        $this->revokedAt = $revokedAt;

        return $this;
    }

    /**
     * Paused or revoked: bearer calls from this cloud are refused.
     */
    public function isOff(): bool
    {
        return self::STATUS_PAUSED === $this->status || self::STATUS_REVOKED === $this->status;
    }

    /**
     * Short badge label for the Users page (OIDC / Nextcloud / …).
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

    /**
     * @return array{mode: string, folders: list<array{id: string, path: string}>}
     */
    public function readScope(): array
    {
        $decoded = null !== $this->readScope ? json_decode($this->readScope, true) : null;
        $mode = is_array($decoded) && is_string($decoded['mode'] ?? null) ? $decoded['mode'] : 'watched';
        $folders = [];
        foreach (is_array($decoded['folders'] ?? null) ? $decoded['folders'] : [] as $folder) {
            if (!is_array($folder)) {
                continue;
            }
            $folders[] = [
                'id' => is_string($folder['id'] ?? null) ? $folder['id'] : '',
                'path' => is_string($folder['path'] ?? null) ? $folder['path'] : '',
            ];
        }

        return ['mode' => $mode, 'folders' => $folders];
    }

    /**
     * @param array{mode: string, folders: list<array{id: string, path: string}>} $scope
     */
    public function setReadScope(array $scope): void
    {
        $this->readScope = json_encode($scope, JSON_THROW_ON_ERROR);
    }
}
