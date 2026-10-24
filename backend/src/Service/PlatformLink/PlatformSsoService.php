<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\ApiKey;
use App\Entity\ExternalIdentity;
use App\Entity\PlatformInstance;
use App\Entity\User;
use App\Repository\ExternalIdentityRepository;
use App\Service\Iam\AuditLogWriter;
use App\Service\PlatformLink\Exception\PlatformDisconnectedException;
use App\Service\PlatformLink\Exception\PlatformSsoException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Organisation login (bind, status) and adoption of a provisioned identity
 * into the canonical (client, instance, uid) row.
 */
final readonly class PlatformSsoService
{
    public function __construct(
        private ExternalIdentityRepository $identities,
        private AuditLogWriter $auditLogWriter,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{bound: bool, mode: ?string, status: ?string}
     */
    public function status(User $user, PlatformInstance $instance, string $externalId): array
    {
        $externalId = $this->uid($externalId);
        $row = $this->identities->findOneByTriple($instance->getClient(), $instance->getInstanceId(), $externalId);
        if (!$row instanceof ExternalIdentity || $row->getUserId() !== (int) $user->getId()) {
            return ['bound' => false, 'mode' => null, 'status' => null];
        }

        return [
            'bound' => ExternalIdentity::STATUS_ACTIVE === $row->getStatus(),
            'mode' => $row->getMode(),
            'status' => $row->getStatus(),
        ];
    }

    /**
     * @return array{success: bool, bound: bool, mode: string, status: string, link_id: int}
     */
    public function bind(User $user, string $azp, PlatformInstance $instance, string $externalId, string $ip): array
    {
        $expected = $instance->getOidcClientId() ?? '';
        if ('' === $expected || $azp !== $expected) {
            throw new PlatformSsoException(Response::HTTP_FORBIDDEN, 'azp_mismatch', 'This login is not the one registered for this cloud.');
        }

        $externalId = $this->uid($externalId);
        $existing = $this->identities->findOneByTriple($instance->getClient(), $instance->getInstanceId(), $externalId);
        if ($existing instanceof ExternalIdentity && $existing->isOff()) {
            throw new PlatformSsoException(Response::HTTP_FORBIDDEN, PlatformDisconnectedException::CODE, PlatformDisconnectedException::MESSAGE);
        }

        $row = $this->identities->upsert(
            (int) $user->getId(),
            $instance->getClient(),
            $externalId,
            $instance->getInstanceId(),
            null,
            ExternalIdentity::MODE_SSO,
        );
        $row->setStatus(ExternalIdentity::STATUS_ACTIVE);
        $this->entityManager->flush();

        $this->auditLogWriter->record(
            (int) $user->getId(),
            'platform_link.bound',
            'platform_link',
            (string) $row->getId(),
            [
                'client' => $instance->getClient(),
                'host' => $instance->getHost(),
                'external_id' => $externalId,
                'mode' => ExternalIdentity::MODE_SSO,
            ],
            $ip,
        );

        return [
            'success' => true,
            'bound' => true,
            'mode' => ExternalIdentity::MODE_SSO,
            'status' => ExternalIdentity::STATUS_ACTIVE,
            'link_id' => (int) $row->getId(),
        ];
    }

    /**
     * Rewrite the key owner's legacy row `(client, '', '<server id>:<uid>')`
     * into the canonical tuple and bind that key. A second call is a 200.
     *
     * @return array{success: bool, mode: ?string, status: string, external_id: string, instance_id: string, link_id: int, api_key_id: ?int}
     */
    public function adopt(User $user, ApiKey $key, PlatformInstance $instance, string $uid): array
    {
        $userId = (int) $user->getId();
        $keyId = $key->getId();
        if ($key->getOwnerId() !== $userId || null === $keyId) {
            throw new PlatformSsoException(Response::HTTP_FORBIDDEN, 'not_owner', 'This key does not belong to that person.');
        }

        $uid = $this->uid($uid);
        $serverId = trim((string) ($instance->getServerId() ?? ''));
        if ('' === $serverId) {
            throw new PlatformSsoException(Response::HTTP_FORBIDDEN, 'server_id_mismatch', 'This cloud has not reported its server id.');
        }

        $source = $instance->getClient();
        $legacy = $this->identities->findOneByTriple($source, '', $serverId.':'.$uid);
        $canonical = $this->identities->findOneByTriple($source, $instance->getInstanceId(), $uid);

        if ($legacy instanceof ExternalIdentity && $legacy->getUserId() !== $userId) {
            throw new PlatformSsoException(Response::HTTP_FORBIDDEN, 'not_owner', 'This key does not belong to that person.');
        }

        if (!$legacy instanceof ExternalIdentity) {
            return $this->replay($canonical, $userId, $keyId);
        }

        if ($canonical instanceof ExternalIdentity) {
            $canonical->setApiKeyId($keyId);
            $this->identities->remove($legacy);

            return $this->adopted($canonical);
        }

        $legacy->setInstanceId($instance->getInstanceId());
        $legacy->setExternalId($uid);
        $legacy->setMode(ExternalIdentity::MODE_PROVISION);
        $legacy->setApiKeyId($keyId);
        $this->entityManager->flush();

        return $this->adopted($legacy);
    }

    /**
     * @return array{success: bool, mode: ?string, status: string, external_id: string, instance_id: string, link_id: int, api_key_id: ?int}
     */
    private function replay(?ExternalIdentity $canonical, int $userId, int $keyId): array
    {
        if (!$canonical instanceof ExternalIdentity) {
            throw new PlatformSsoException(Response::HTTP_FORBIDDEN, 'server_id_mismatch', 'No identity matches this cloud\'s server id.');
        }

        $ownsRow = $canonical->getUserId() === $userId;
        $ownsKey = $canonical->getApiKeyId() === $keyId;
        if (!$ownsRow && !$ownsKey) {
            throw new PlatformSsoException(Response::HTTP_FORBIDDEN, 'not_owner', 'This key does not belong to that person.');
        }

        $canonical->setApiKeyId($keyId);
        $this->entityManager->flush();

        return $this->adopted($canonical);
    }

    /**
     * @return array{success: bool, mode: ?string, status: string, external_id: string, instance_id: string, link_id: int, api_key_id: ?int}
     */
    private function adopted(ExternalIdentity $row): array
    {
        return [
            'success' => true,
            'mode' => $row->getMode(),
            'status' => $row->getStatus(),
            'external_id' => $row->getExternalId(),
            'instance_id' => $row->getInstanceId(),
            'link_id' => (int) $row->getId(),
            'api_key_id' => $row->getApiKeyId(),
        ];
    }

    private function uid(string $externalId): string
    {
        $externalId = trim($externalId);
        if ('' === $externalId || strlen($externalId) > PlatformInstanceService::EXTERNAL_ID_MAX_LENGTH) {
            throw new PlatformSsoException(Response::HTTP_BAD_REQUEST, 'invalid_external_id', 'A platform user id is required.');
        }

        return $externalId;
    }
}
