<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\Connection;
use App\Entity\CustomTool;
use App\Repository\ConnectionRepository;
use App\Repository\CustomToolRepository;
use App\Service\Credential\CredentialNotFoundException;
use App\Service\Credential\CredentialVaultInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The user's connections and custom tools. A connection's secret is
 * forgotten through the vault inside the transaction, so a vault failure
 * rolls the whole deletion back instead of leaving a secret behind. A custom
 * tool only points at a login the user owns; that login goes with its
 * connection or with {@see CredentialEraser}.
 */
#[AsTaggedItem(priority: 195)]
final readonly class ConnectionEraser implements UserDataEraserInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private ConnectionRepository $connections,
        private CustomToolRepository $customTools,
        private CredentialVaultInterface $vault,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        $this->customTools->deleteByOwner($erasure->userId);

        foreach ($this->connections->findByOwner($erasure->userId) as $connection) {
            $credentialId = $connection->getCredentialId();
            if (null !== $credentialId) {
                $this->forget($credentialId, $erasure->userId);
            }
            $this->em->remove($connection);
        }
    }

    public static function covers(): array
    {
        return [Connection::class, CustomTool::class];
    }

    private function forget(int $credentialId, int $ownerId): void
    {
        try {
            $this->vault->forget($credentialId, $ownerId);
        } catch (CredentialNotFoundException) {
            // A dangling reference: the secret is already gone.
        }
    }
}
