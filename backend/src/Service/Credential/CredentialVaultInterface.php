<?php

declare(strict_types=1);

namespace App\Service\Credential;

/**
 * AES credential vault. Callers receive plaintext only from {@see reveal()};
 * HTTP responses and logs must never contain it.
 */
interface CredentialVaultInterface
{
    public function store(int $ownerId, string $kind, string $plaintext): int;

    public function reveal(int $credentialId, int $ownerId): string;

    public function rotate(int $credentialId, int $ownerId, string $plaintext): void;

    public function forget(int $credentialId, int $ownerId): void;
}
