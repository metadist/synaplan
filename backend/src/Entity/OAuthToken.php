<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OAuthTokenRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One thing Synaplan's OAuth2 server issued or saw: an access token (by its
 * jti), a refresh token, an authorization code, or a client assertion's jti
 * (kept until it expires, so it cannot be replayed). Only identifiers and
 * metadata are stored; the token values themselves are signed or encrypted
 * and never written here.
 */
#[ORM\Entity(repositoryClass: OAuthTokenRepository::class)]
#[ORM\Table(name: 'BOAUTHTOKENS')]
#[ORM\Index(columns: ['BCLIENTID', 'BUSERID'], name: 'idx_oauthtoken_client_user')]
#[ORM\Index(columns: ['BEXPIRES'], name: 'idx_oauthtoken_expires')]
class OAuthToken
{
    public const KIND_ACCESS = 'access';
    public const KIND_REFRESH = 'refresh';
    public const KIND_CODE = 'code';
    public const KIND_ASSERTION = 'assertion';

    #[ORM\Id]
    #[ORM\Column(name: 'BID', length: 128)]
    private string $id;

    #[ORM\Column(name: 'BKIND', length: 16)]
    private string $kind;

    #[ORM\Column(name: 'BCLIENTID', length: 191)]
    private string $clientId;

    #[ORM\Column(name: 'BUSERID', type: 'bigint', nullable: true)]
    private ?int $userId;

    /** @var list<string> */
    #[ORM\Column(name: 'BSCOPES', type: 'json')]
    private array $scopes;

    /** For a refresh token: the access token issued with it. */
    #[ORM\Column(name: 'BPARENTID', length: 128, nullable: true)]
    private ?string $parentId;

    /** The cloud account the grant was issued for. Null for a client assertion. */
    #[ORM\Column(name: 'BEXTERNALID', length: 191, nullable: true)]
    private ?string $externalId;

    #[ORM\Column(name: 'BEXPIRES', type: 'bigint')]
    private int $expires;

    #[ORM\Column(name: 'BREVOKED', type: 'boolean', options: ['default' => false])]
    private bool $revoked = false;

    /**
     * @param list<string> $scopes
     */
    public function __construct(string $id, string $kind, string $clientId, ?int $userId, array $scopes, int $expires, ?string $parentId = null, ?string $externalId = null)
    {
        $this->id = $id;
        $this->kind = $kind;
        $this->clientId = $clientId;
        $this->userId = $userId;
        $this->scopes = $scopes;
        $this->expires = $expires;
        $this->parentId = $parentId;
        $this->externalId = $externalId;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    /**
     * @return list<string>
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function getParentId(): ?string
    {
        return $this->parentId;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function getExpires(): int
    {
        return $this->expires;
    }

    public function expireAt(int $expires): void
    {
        $this->expires = $expires;
    }

    public function isRevoked(): bool
    {
        return $this->revoked;
    }

    public function revoke(): void
    {
        $this->revoked = true;
    }
}
