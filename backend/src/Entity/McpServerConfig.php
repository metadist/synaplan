<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\McpServerConfigRepository;
use App\Service\EncryptionService;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user's connection to an EXTERNAL MCP server (release-4.0 plan 09 §3.2).
 *
 * Synaplan is an MCP server itself (`/mcp`); this entity is the other
 * direction — the outbound MCP CLIENT's per-user server registry. Tools
 * discovered on these servers feed the planner's dynamic `mcp_fetch`
 * sub-catalog (Sprint 6).
 *
 * First-class entity (not plugin_data) following the proven
 * {@see InboundEmailHandler} pattern: dedicated table, encrypted secret via
 * {@see EncryptionService}, user-scoped repository lookups so cross-tenant
 * access is structurally impossible.
 */
#[ORM\Entity(repositoryClass: McpServerConfigRepository::class)]
#[ORM\Table(name: 'BMCPSERVERS')]
#[ORM\Index(columns: ['BUSERID'], name: 'idx_mcp_user')]
class McpServerConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BUSERID', type: 'bigint')]
    private int $userId;

    #[ORM\Column(name: 'BNAME', length: 255)]
    private string $name = '';

    /** Streamable HTTP endpoint URL (https://host/mcp). */
    #[ORM\Column(name: 'BURL', length: 1024)]
    private string $url = '';

    /**
     * HTTP header carrying the credential (e.g. "Authorization" or
     * "X-API-KEY"). Empty = no authentication.
     */
    #[ORM\Column(name: 'BAUTHHEADER', length: 128, options: ['default' => ''])]
    private string $authHeader = '';

    /** Encrypted header value (AES-256-CBC via EncryptionService). */
    #[ORM\Column(name: 'BAUTHTOKEN', type: 'text')]
    private string $authToken = '';

    #[ORM\Column(name: 'BENABLED', type: 'boolean', options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(name: 'BCREATED', type: 'string', length: 20)]
    private string $created;

    #[ORM\Column(name: 'BUPDATED', type: 'string', length: 20)]
    private string $updated;

    public function __construct()
    {
        $this->created = date('YmdHis');
        $this->updated = date('YmdHis');
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        $this->touch();

        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): self
    {
        $this->url = $url;
        $this->touch();

        return $this;
    }

    public function getAuthHeader(): string
    {
        return $this->authHeader;
    }

    public function setAuthHeader(string $authHeader): self
    {
        $this->authHeader = $authHeader;
        $this->touch();

        return $this;
    }

    /** Encrypted value — use {@see getDecryptedAuthToken()} in the service layer. */
    public function getAuthToken(): string
    {
        return $this->authToken;
    }

    public function hasAuthToken(): bool
    {
        return '' !== $this->authToken;
    }

    public function getDecryptedAuthToken(EncryptionService $encryptionService): string
    {
        if ('' === $this->authToken) {
            return '';
        }

        return $encryptionService->decrypt($this->authToken);
    }

    public function setDecryptedAuthToken(string $plaintext, EncryptionService $encryptionService): self
    {
        $this->authToken = '' === $plaintext ? '' : $encryptionService->encrypt($plaintext);
        $this->touch();

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        $this->touch();

        return $this;
    }

    public function getCreated(): string
    {
        return $this->created;
    }

    public function getUpdated(): string
    {
        return $this->updated;
    }

    public function touch(): self
    {
        $this->updated = date('YmdHis');

        return $this;
    }
}
