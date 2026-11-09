<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\ApiKey;
use App\Entity\ExternalIdentity;
use App\Entity\PlatformInstance;
use App\Repository\ApiKeyRepository;
use App\Repository\ExternalIdentityRepository;
use App\Repository\OAuthTokenRepository;
use App\Repository\PlatformInstanceRepository;
use App\Service\Iam\AuditLogWriter;
use App\Service\PlatformLink\Exception\PlatformManagedByAdministratorException;
use App\Service\PlatformLink\Exception\PlatformSsoException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A person's links with clouds: listing them, and ending, turning off or
 * resuming one. Ending a link also ends the cloud's OAuth2 grant for that
 * person.
 */
final readonly class PlatformLinkExchangeService
{
    public function __construct(
        private ApiKeyRepository $apiKeyRepository,
        private ExternalIdentityRepository $externalIdentityRepository,
        private PlatformInstanceRepository $instanceRepository,
        private AuditLogWriter $auditLogWriter,
        private DeliveryNotifier $notifier,
        private LifecycleEffect $effects,
        private OAuthTokenRepository $oauthTokens,
    ) {
    }

    /**
     * @return list<array{id: int, client: string, host: string, external_id: string, key_id: int|null, key_label: string, created: int, last_seen: int, mode: string|null, status: string, can_copy: bool, can_read: bool, read_scope: array{mode: string, folders: list<string>}}>
     */
    public function listForUser(int $userId): array
    {
        $out = [];
        foreach ($this->externalIdentityRepository->findByUserId($userId) as $identity) {
            if ('' === $identity->getInstanceId()) {
                continue;
            }
            $instance = $this->instanceRepository->findByInstanceId($identity->getInstanceId());
            if (!$instance instanceof PlatformInstance || ExternalIdentity::STATUS_REVOKED === $identity->getStatus()) {
                continue;
            }
            $keyLabel = '';
            if (null !== $identity->getApiKeyId()) {
                $key = $this->apiKeyRepository->find($identity->getApiKeyId());
                // Without its key the platform cannot act for the person: the link is over.
                if (!$key instanceof ApiKey) {
                    continue;
                }
                $keyLabel = $key->getName();
            }
            $out[] = [
                'id' => (int) $identity->getId(),
                'client' => $instance->getClient(),
                'host' => $instance->getHost(),
                'external_id' => $identity->getExternalId(),
                'key_id' => $identity->getApiKeyId(),
                'key_label' => $keyLabel,
                'created' => $identity->getCreated(),
                'last_seen' => $identity->getLastSeen(),
                'mode' => $identity->getMode(),
                'status' => $identity->getStatus(),
                'can_copy' => $instance->canReceiveCopies() && !$identity->isOff(),
                'can_read' => $instance->reports('pickups'),
                'read_scope' => $this->readScopeOf($identity),
            ];
        }

        return $out;
    }

    /**
     * @return array{mode: string, folders: list<string>}
     */
    private function readScopeOf(ExternalIdentity $identity): array
    {
        $scope = $identity->readScope();
        $folders = [];
        foreach ($scope['folders'] as $folder) {
            if ('' !== $folder['path']) {
                $folders[] = $folder['path'];
            }
        }

        return ['mode' => in_array($scope['mode'], ['watched', 'allowed', 'all'], true) ? $scope['mode'] : 'watched', 'folders' => $folders];
    }

    /**
     * Revoking a key ends the link that holds it, as a disconnect does: a
     * platform revokes its key when the person disconnects there, and must
     * not stay listed as connected.
     */
    public function endLinkOfKey(int $userId, int $apiKeyId, string $ip): void
    {
        $identity = $this->externalIdentityRepository->findOneByApiKeyId($apiKeyId);
        if (!$identity instanceof ExternalIdentity || $identity->getUserId() !== $userId) {
            return;
        }

        $linkId = (string) $identity->getId();
        if (ExternalIdentity::MODE_SSO === $identity->getMode() && ExternalIdentity::STATUS_REVOKED !== $identity->getStatus()) {
            $identity->setAccess(false, !$identity->cloudDisabled());
            $this->effects->onPaused($identity);
        } else {
            $identity->setStatus(ExternalIdentity::STATUS_REVOKED);
            $this->effects->onDeleted($identity);
        }
        $identity->setRevokedAt(time());
        $this->externalIdentityRepository->save($identity);
        $this->notifier->identityRevoked($identity);

        $this->auditLogWriter->record(
            $userId,
            'platform_link.disconnected',
            'platform_link',
            $linkId,
            null,
            $ip,
        );
    }

    public function disconnect(int $userId, int $linkId, string $ip): void
    {
        $identity = $this->externalIdentityRepository->find($linkId);
        if (!$identity instanceof ExternalIdentity || $identity->getUserId() !== $userId) {
            throw new Exception\PlatformInstanceNotFoundException('Link not found.');
        }
        if (ExternalIdentity::MODE_PROVISION === $identity->getMode()) {
            throw new PlatformManagedByAdministratorException();
        }

        if (null !== $identity->getApiKeyId()) {
            $key = $this->apiKeyRepository->find($identity->getApiKeyId());
            if ($key instanceof ApiKey) {
                $this->apiKeyRepository->remove($key, false);
            }
        }
        if ('' !== $identity->getInstanceId()) {
            $this->oauthTokens->revokeAll($identity->getInstanceId(), $userId);
        }
        $identity->setStatus(ExternalIdentity::STATUS_REVOKED);
        $identity->setRevokedAt(time());
        $this->externalIdentityRepository->save($identity);
        $this->effects->onDeleted($identity);
        $this->notifier->identityRevoked($identity);

        $this->auditLogWriter->record(
            $userId,
            'platform_link.disconnected',
            'platform_link',
            (string) $linkId,
            null,
            $ip,
        );
    }

    public function turnOff(int $userId, int $linkId, string $ip): void
    {
        $updated = $this->externalIdentityRepository->modify($linkId, function (ExternalIdentity $identity) use ($userId): void {
            $this->guardOwner($identity, $userId);
            $identity->setAccess(false, !$identity->cloudDisabled());
            $this->effects->onPaused($identity);
        });
        if (!$updated instanceof ExternalIdentity) {
            throw new Exception\PlatformInstanceNotFoundException('Link not found.');
        }
        $this->auditLogWriter->record($userId, 'platform_link.turned_off', 'platform_link', (string) $linkId, null, $ip);
    }

    public function resume(int $userId, int $linkId, string $ip): void
    {
        $updated = $this->externalIdentityRepository->modify($linkId, function (ExternalIdentity $identity) use ($userId): void {
            $this->guardOwner($identity, $userId);
            if (!$identity->ownerTurnedOff()) {
                throw new PlatformSsoException(Response::HTTP_CONFLICT, 'cloud_disabled', 'Your cloud turned this connection off.');
            }
            $identity->setAccess(true, !$identity->cloudDisabled());
            $identity->setRevokedAt(null);
            $this->effects->onResumed($identity);
        });
        if (!$updated instanceof ExternalIdentity) {
            throw new Exception\PlatformInstanceNotFoundException('Link not found.');
        }
        $this->auditLogWriter->record($userId, 'platform_link.resumed', 'platform_link', (string) $linkId, null, $ip);
    }

    private function guardOwner(ExternalIdentity $identity, int $userId): void
    {
        if ($identity->getUserId() !== $userId) {
            throw new Exception\PlatformInstanceNotFoundException('Link not found.');
        }
        if (ExternalIdentity::STATUS_REVOKED === $identity->getStatus()) {
            throw new PlatformSsoException(Response::HTTP_CONFLICT, 'identity_revoked', 'This connection was removed by the cloud.');
        }
        if (ExternalIdentity::MODE_SSO !== $identity->getMode()) {
            throw new PlatformSsoException(Response::HTTP_FORBIDDEN, 'not_organisation_login', 'Turn off is for organisation login.');
        }
    }

    /**
     * @return array{client: string, host: string}|null
     */
    public function linkedPlatformForKey(int $keyId): ?array
    {
        return $this->linkedPlatformsForKeys([$keyId])[$keyId] ?? null;
    }

    /**
     * Two queries for the whole list (identities by key, instances by id)
     * instead of two per key — used by the API-keys page.
     *
     * @param list<int> $keyIds
     *
     * @return array<int, array{client: string, host: string}> keyed by API key id; keys without a link are absent
     */
    public function linkedPlatformsForKeys(array $keyIds): array
    {
        $identities = array_filter(
            $this->externalIdentityRepository->findByApiKeyIds($keyIds),
            static fn (ExternalIdentity $identity): bool => null !== $identity->getApiKeyId() && '' !== $identity->getInstanceId(),
        );
        if ([] === $identities) {
            return [];
        }

        $instances = $this->instanceRepository->findByInstanceIds(array_values(array_unique(array_map(
            static fn (ExternalIdentity $identity): string => $identity->getInstanceId(),
            $identities,
        ))));

        $linked = [];
        foreach ($identities as $identity) {
            $instance = $instances[$identity->getInstanceId()] ?? null;
            if ($instance instanceof PlatformInstance) {
                $linked[(int) $identity->getApiKeyId()] = [
                    'client' => $instance->getClient(),
                    'host' => $instance->getHost(),
                ];
            }
        }

        return $linked;
    }
}
