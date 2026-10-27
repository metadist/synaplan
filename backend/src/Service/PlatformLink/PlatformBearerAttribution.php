<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\PlatformInstance;
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
     * Signing in again is allowed when the person turned the connection off.
     * A cloud that disabled or removed them is still refused.
     *
     * @param array<string, mixed> $claims
     */
    public function attribute(User $user, array $claims, bool $allowOwnerReconnect = false): void
    {
        $azp = $claims['azp'] ?? null;
        $userId = $user->getId();
        if (!is_string($azp) || '' === $azp || null === $userId) {
            return;
        }

        $instances = $this->instances->findByOidcClientId($azp);
        if ([] === $instances) {
            return;
        }

        $matched = [];
        $hasActiveInstance = false;
        $onlyRevokedInstances = true;
        foreach ($instances as $instance) {
            if ($instance->isActive()) {
                $hasActiveInstance = true;
                $onlyRevokedInstances = false;
            } elseif (PlatformInstance::STATUS_REVOKED !== $instance->getStatus()) {
                $onlyRevokedInstances = false;
            }

            if (!$instance->isActive()) {
                continue;
            }

            foreach ($this->identities->findForUserOnInstance((int) $userId, $instance->getClient(), $instance->getInstanceId()) as $row) {
                if ($row->refusesReconnect() || ($row->ownerTurnedOff() && !$allowOwnerReconnect)) {
                    throw new PlatformDisconnectedException();
                }
                if ($row->isOff()) {
                    continue;
                }
                $matched[] = $row;
            }
        }

        if ([] === $matched) {
            if (!$hasActiveInstance && $onlyRevokedInstances) {
                throw new PlatformDisconnectedException();
            }

            return;
        }

        foreach ($matched as $row) {
            $row->touchLastSeen();
        }
        $this->entityManager->flush();
    }
}
