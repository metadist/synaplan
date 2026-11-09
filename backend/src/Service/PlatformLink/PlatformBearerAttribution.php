<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\ExternalIdentity;
use App\Entity\PlatformInstance;
use App\Entity\User;
use App\Repository\ExternalIdentityRepository;
use App\Repository\PlatformInstanceRepository;
use App\Service\Iam\AuditLogWriter;
use App\Service\PlatformLink\Exception\PlatformDisconnectedException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * When a bearer token's azp is a cloud's people client, that person's link
 * with the cloud is looked up, refused when it is off (D8), and created on
 * first use when there is none yet: with a shared identity provider the
 * token itself says who the person is in the cloud (sub) and which cloud
 * it is (azp), like OpenCloud provisions its own accounts on first login.
 * A link the person or the cloud ended is never re-created this way.
 *
 * A client that is not a cloud's is left alone (C1).
 */
final readonly class PlatformBearerAttribution
{
    public function __construct(
        private PlatformInstanceRepository $instances,
        private ExternalIdentityRepository $identities,
        private EntityManagerInterface $entityManager,
        private LifecycleEffect $effects,
        private AuditLogWriter $audit,
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

        $instances = $this->instances->findByOidcClientId($azp);
        if ([] === $instances) {
            return;
        }

        $matched = [];
        $active = [];
        $onlyRevokedInstances = true;
        foreach ($instances as $instance) {
            if ($instance->isActive()) {
                $active[] = $instance;
                $onlyRevokedInstances = false;
            } elseif (PlatformInstance::STATUS_REVOKED !== $instance->getStatus()) {
                $onlyRevokedInstances = false;
            }

            if (!$instance->isActive()) {
                continue;
            }

            foreach ($this->identities->findForUserOnInstance((int) $userId, $instance->getClient(), $instance->getInstanceId()) as $row) {
                if ($row->refusesReconnect() || $row->ownerTurnedOff()) {
                    throw new PlatformDisconnectedException();
                }
                if ($row->isOff()) {
                    continue;
                }
                $matched[] = $row;
            }
        }

        if ([] === $matched) {
            if ([] === $active && $onlyRevokedInstances) {
                throw new PlatformDisconnectedException();
            }
            if (1 === count($active)) {
                $this->provisionOnUse($user, $active[0], $claims);
            }

            return;
        }

        foreach ($matched as $row) {
            $row->touchLastSeen();
        }
        $this->entityManager->flush();
    }

    /**
     * Whether the token came through a cloud's client: then it reaches only
     * what a cloud may reach for a person, like an access token Synaplan
     * issued to a cloud.
     *
     * @param array<string, mixed> $claims
     */
    public function isCloudClient(array $claims): bool
    {
        $azp = $claims['azp'] ?? null;
        if (!is_string($azp) || '' === $azp) {
            return false;
        }
        foreach ($this->instances->findByOidcClientId($azp) as $instance) {
            if ($instance->isActive()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first call of this person through this cloud: link them as the
     * token's subject. Nothing happens when that cloud account is already
     * someone else's link.
     *
     * @param array<string, mixed> $claims
     */
    private function provisionOnUse(User $user, PlatformInstance $instance, array $claims): void
    {
        $externalId = is_string($claims['sub'] ?? null) ? trim($claims['sub']) : '';
        if ('' === $externalId || strlen($externalId) > PlatformInstanceService::EXTERNAL_ID_MAX_LENGTH) {
            return;
        }
        $existing = $this->identities->findOneByTriple($instance->getClient(), $instance->getInstanceId(), $externalId);
        if ($existing instanceof ExternalIdentity) {
            return;
        }
        $row = $this->identities->upsert((int) $user->getId(), $instance->getClient(), $externalId, $instance->getInstanceId(), null, ExternalIdentity::MODE_SSO);
        $row->setStatus(ExternalIdentity::STATUS_ACTIVE);
        $this->entityManager->flush();
        $this->effects->onResumed($row);
        $this->audit->record((int) $user->getId(), 'platform_link.bound', 'platform_link', (string) $row->getId(), [
            'client' => $instance->getClient(),
            'host' => $instance->getHost(),
            'external_id' => $externalId,
            'mode' => ExternalIdentity::MODE_SSO,
        ]);
    }
}
