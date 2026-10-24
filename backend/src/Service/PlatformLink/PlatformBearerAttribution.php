<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\User;
use App\Repository\ExternalIdentityRepository;
use App\Repository\PlatformInstanceRepository;
use App\Service\PlatformLink\Exception\PlatformDisconnectedException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * When a bearer token's azp is a registered cloud, touch that person's
 * identity row — or refuse it when the connection is off (D8).
 *
 * A client that is not registered is left alone (C1).
 */
final readonly class PlatformBearerAttribution
{
    public function __construct(
        private PlatformInstanceRepository $instances,
        private ExternalIdentityRepository $identities,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string, mixed> $claims
     */
    public function attribute(User $user, array $claims): void
    {
        $azp = $claims['azp'] ?? null;
        $userId = $user->getId();
        if (!is_string($azp) || '' === $azp || null === $userId) {
            return;
        }

        $matched = [];
        foreach ($this->instances->findByOidcClientId($azp) as $instance) {
            foreach ($this->identities->findForUserOnInstance((int) $userId, $instance->getClient(), $instance->getInstanceId()) as $row) {
                if ($row->isOff()) {
                    throw new PlatformDisconnectedException();
                }
                $matched[] = $row;
            }
        }

        if ([] === $matched) {
            return;
        }

        foreach ($matched as $row) {
            $row->touchLastSeen();
        }
        $this->entityManager->flush();
    }
}
