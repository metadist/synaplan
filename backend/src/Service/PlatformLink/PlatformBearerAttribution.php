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
 * token itself says who the person is in the cloud (sub, or the claim
 * {@see OidcCloud::userClaimFor()} names) and which cloud it is (azp), like
 * OpenCloud provisions its own accounts on first login. A link created this
 * way follows the token when that value changes.
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
        private OidcCloud $cloud,
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
                $matched[] = [$instance, $row];
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

        foreach ($matched as [$instance, $row]) {
            $this->followToken($user, $instance, $row, $claims);
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
     * Whether `azp` is a cloud's client, approved or not. A session cookie is
     * refused for such a token: the bearer path is where a cloud acts, and
     * there it is limited to what a cloud may do.
     *
     * @param array<string, mixed> $claims
     */
    public function namesCloud(array $claims): bool
    {
        $azp = $claims['azp'] ?? null;

        return is_string($azp) && '' !== $azp && [] !== $this->instances->findByOidcClientId($azp);
    }

    /**
     * The first call of this person through this cloud: link them as the
     * token names them. Nothing happens when that cloud account is already
     * someone else's link.
     *
     * @param array<string, mixed> $claims
     */
    private function provisionOnUse(User $user, PlatformInstance $instance, array $claims): void
    {
        $externalId = $this->externalId($instance, $claims);
        if ('' === $externalId) {
            return;
        }
        $existing = $this->identities->findOneByTriple($instance->getClient(), $instance->getInstanceId(), $externalId);
        if ($existing instanceof ExternalIdentity) {
            return;
        }
        // Another first-use request of this same person may have inserted the
        // row after the lookup above. That request owns the resume and the
        // audit entry; this one continues with the row that won.
        $row = $this->identities->insertIfAbsent(
            (int) $user->getId(),
            $instance->getClient(),
            $externalId,
            $instance->getInstanceId(),
            null,
            ExternalIdentity::MODE_SSO,
        );
        if (!$row instanceof ExternalIdentity) {
            return;
        }
        // The lock is held through the resume and the audit entry. A disable
        // that commits first is visible here, and one that arrives second
        // waits until this transaction ends, so it pauses the watches this
        // resume just started instead of racing it.
        $this->entityManager->wrapInTransaction(function () use ($user, $instance, $externalId): void {
            $current = $this->identities->lock($instance->getClient(), $instance->getInstanceId(), $externalId);
            if (!$current instanceof ExternalIdentity || $current->isOff()) {
                return;
            }
            $current->setStatus(ExternalIdentity::STATUS_ACTIVE);
            $this->entityManager->flush();
            $this->effects->onResumed($current);
            $this->audit->record((int) $user->getId(), 'platform_link.bound', 'platform_link', (string) $current->getId(), [
                'client' => $instance->getClient(),
                'host' => $instance->getHost(),
                'external_id' => $externalId,
                'mode' => ExternalIdentity::MODE_SSO,
            ]);
        });
    }

    /**
     * A link created on first use names the person as the token did then.
     * When the token names them differently now (the identity provider
     * created the account anew, or another claim was configured), the link
     * takes the new value, so the cloud is told about a person it knows.
     * Nothing happens when that value is already another link.
     *
     * @param array<string, mixed> $claims
     */
    private function followToken(User $user, PlatformInstance $instance, ExternalIdentity $row, array $claims): void
    {
        $externalId = $this->externalId($instance, $claims);
        $previous = $row->getExternalId();
        if ('' === $externalId || $externalId === $previous || ExternalIdentity::MODE_SSO !== $row->getMode()) {
            return;
        }
        if ($this->identities->findOneByTriple($instance->getClient(), $instance->getInstanceId(), $externalId) instanceof ExternalIdentity) {
            return;
        }
        // Another request of this same person may have moved the row after
        // the lookup above: then nothing is left under the previous value,
        // and that request owns the audit entry.
        $this->entityManager->wrapInTransaction(function () use ($user, $instance, $previous, $externalId): void {
            $current = $this->identities->lock($instance->getClient(), $instance->getInstanceId(), $previous);
            if (
                !$current instanceof ExternalIdentity
                || $current->getUserId() !== (int) $user->getId()
                || $current->isOff()
                || ExternalIdentity::MODE_SSO !== $current->getMode()
            ) {
                return;
            }
            $current->setExternalId($externalId);
            $this->entityManager->flush();
            $this->audit->record((int) $user->getId(), 'platform_link.rebound', 'platform_link', (string) $current->getId(), [
                'client' => $instance->getClient(),
                'host' => $instance->getHost(),
                'external_id' => $externalId,
                'previous_external_id' => $previous,
            ]);
        });
    }

    /**
     * How the token names this person in that cloud. Empty when the token
     * lacks the claim or the value does not fit an external id.
     *
     * @param array<string, mixed> $claims
     */
    private function externalId(PlatformInstance $instance, array $claims): string
    {
        $externalId = OidcCloud::claimValue($claims, $this->cloud->userClaimFor($instance));

        return strlen($externalId) > PlatformInstanceService::EXTERNAL_ID_MAX_LENGTH ? '' : $externalId;
    }
}
