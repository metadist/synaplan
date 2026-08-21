<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\OAuth;

use App\Service\Credential\CredentialNotFoundException;
use App\Service\Credential\CredentialVaultInterface;

/**
 * Vault double that keeps secrets in memory and still enforces the ownership
 * check — a test must not be able to read another owner's credential.
 */
final class InMemoryCredentialVault implements CredentialVaultInterface
{
    /** @var array<int, array{owner: int, kind: string, secret: string}> */
    public array $stored = [];

    private int $nextId = 1;

    public function store(int $ownerId, string $kind, string $plaintext): int
    {
        $id = $this->nextId++;
        $this->stored[$id] = ['owner' => $ownerId, 'kind' => $kind, 'secret' => $plaintext];

        return $id;
    }

    public function reveal(int $credentialId, int $ownerId): string
    {
        return $this->owned($credentialId, $ownerId)['secret'];
    }

    public function rotate(int $credentialId, int $ownerId, string $plaintext): void
    {
        $this->owned($credentialId, $ownerId);
        $this->stored[$credentialId]['secret'] = $plaintext;
    }

    public function forget(int $credentialId, int $ownerId): void
    {
        $this->owned($credentialId, $ownerId);
        unset($this->stored[$credentialId]);
    }

    /**
     * @return array{owner: int, kind: string, secret: string}
     */
    private function owned(int $credentialId, int $ownerId): array
    {
        $row = $this->stored[$credentialId] ?? null;
        if (null === $row || $row['owner'] !== $ownerId) {
            throw new CredentialNotFoundException($credentialId);
        }

        return $row;
    }
}
