<?php

declare(strict_types=1);

namespace App\Service\Credential;

use App\Entity\Credential;
use App\Repository\CredentialRepository;
use App\Service\EncryptionService;
use Psr\Log\LoggerInterface;

final readonly class AesCredentialVault implements CredentialVaultInterface
{
    public function __construct(
        private CredentialRepository $credentials,
        private EncryptionService $encryption,
        private LoggerInterface $logger,
    ) {
    }

    public function store(int $ownerId, string $kind, string $plaintext): int
    {
        $credential = new Credential($ownerId, $kind);
        $credential->setSecret($this->encryption->encrypt($plaintext));
        $this->credentials->save($credential);
        $id = $credential->getId();
        if (null === $id) {
            throw new \RuntimeException('Credential persist did not assign an id');
        }

        $this->logger->info('Credential stored', [
            'credential_id' => $id,
            'owner_id' => $ownerId,
            'kind' => $kind,
        ]);

        return $id;
    }

    public function reveal(int $credentialId, int $ownerId): string
    {
        return $this->encryption->decrypt($this->owned($credentialId, $ownerId)->getSecret());
    }

    public function rotate(int $credentialId, int $ownerId, string $plaintext): void
    {
        $credential = $this->owned($credentialId, $ownerId);
        $credential->setSecret($this->encryption->encrypt($plaintext));
        $this->credentials->save($credential);
        $this->logger->info('Credential rotated', [
            'credential_id' => $credentialId,
            'owner_id' => $ownerId,
        ]);
    }

    public function forget(int $credentialId, int $ownerId): void
    {
        $this->credentials->remove($this->owned($credentialId, $ownerId));
        $this->logger->info('Credential forgotten', [
            'credential_id' => $credentialId,
            'owner_id' => $ownerId,
        ]);
    }

    private function owned(int $credentialId, int $ownerId): Credential
    {
        $credential = $this->credentials->findByIdAndOwner($credentialId, $ownerId);
        if (null === $credential) {
            throw new CredentialNotFoundException($credentialId);
        }

        return $credential;
    }
}
