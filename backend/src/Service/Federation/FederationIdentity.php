<?php

declare(strict_types=1);

namespace App\Service\Federation;

/**
 * This server's partner identity. The secret key never leaves the store.
 */
final readonly class FederationIdentity
{
    public function __construct(
        public string $publicKey,
        public string $secretKey,
        public string $name,
        public bool $opened,
    ) {
    }

    public function withOpened(bool $opened, ?string $name = null): self
    {
        return new self(
            $this->publicKey,
            $this->secretKey,
            $name ?? $this->name,
            $opened,
        );
    }
}
