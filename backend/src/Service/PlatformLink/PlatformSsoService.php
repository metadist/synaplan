<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\ApiKey;
use App\Entity\ExternalIdentity;
use App\Entity\PlatformInstance;
use App\Entity\User;
use App\Repository\ApiKeyRepository;
use App\Repository\ExternalIdentityRepository;
use App\Security\ApiKeyScope;
use App\Service\Billing\PremiumFeatureGate;
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
    private const BACKGROUND_LABEL_SUFFIX = ' — while you are away';

    private const NAME_MAX = 128;

    public function __construct(
        private ExternalIdentityRepository $identities,
        private ApiKeyRepository $apiKeys,
        private PremiumFeatureGate $premiumFeatureGate,
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
        if ($existing instanceof ExternalIdentity && $existing->getUserId() !== (int) $user->getId()) {
            throw new PlatformSsoException(Response::HTTP_FORBIDDEN, 'identity_conflict', 'This person is already linked to another Synaplan account.');
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
     * Mint the unattended key for an organisation-login identity. The
     * plaintext is in the answer only the first time.
     *
     * @return array{success: bool, api_key_id: int, scopes: list<string>, key?: string}
     */
    public function backgroundKey(User $user, PlatformInstance $instance, string $externalId, string $ip): array
    {
        $externalId = $this->uid($externalId);
        $row = $this->activeOrganisationRow($user, $instance, $externalId);
        $existing = $this->activeBackgroundKey($row);
        if ($existing instanceof ApiKey) {
            return [
                'success' => true,
                'api_key_id' => (int) $existing->getId(),
                'scopes' => ApiKeyScope::backgroundKeyScopes(),
            ];
        }

        $this->premiumFeatureGate->assertApiKeysAllowed($user);
        $previous = $this->boundKey($row);
        if ($previous instanceof ApiKey && $this->isBackgroundKey($previous)) {
            $previous->setStatus('revoked');
        }

        $plain = 'sk_'.bin2hex(random_bytes(29));
        $apiKey = new ApiKey();
        $apiKey->setOwner($user);
        $apiKey->setKey($plain);
        $apiKey->setName($this->backgroundLabel($instance, $externalId));
        $apiKey->setStatus('active');
        $apiKey->setScopes(ApiKeyScope::backgroundKeyScopes());
        $this->entityManager->persist($apiKey);
        $this->entityManager->flush();

        $keyId = $apiKey->getId();
        if (null === $keyId) {
            throw new \LogicException('Minted background key has no id.');
        }
        $row->setApiKeyId($keyId);
        $this->entityManager->flush();

        $this->auditLogWriter->record(
            (int) $user->getId(),
            'platform_link.background_key',
            'platform_link',
            (string) $row->getId(),
            [
                'client' => $instance->getClient(),
                'host' => $instance->getHost(),
                'external_id' => $externalId,
                'api_key_id' => $keyId,
            ],
            $ip,
        );

        return [
            'success' => true,
            'api_key_id' => $keyId,
            'scopes' => ApiKeyScope::backgroundKeyScopes(),
            'key' => $plain,
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
            if ($canonical->getUserId() !== $userId) {
                throw new PlatformSsoException(Response::HTTP_FORBIDDEN, 'not_owner', 'This key does not belong to that person.');
            }
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

    private function activeOrganisationRow(User $user, PlatformInstance $instance, string $externalId): ExternalIdentity
    {
        $row = $this->identities->findOneByTriple($instance->getClient(), $instance->getInstanceId(), $externalId);
        if (!$row instanceof ExternalIdentity || $row->getUserId() !== (int) $user->getId()) {
            throw new PlatformSsoException(Response::HTTP_FORBIDDEN, 'not_bound', 'Connect this cloud in Synaplan before watching folders.');
        }
        if ($row->isOff()) {
            throw new PlatformSsoException(Response::HTTP_FORBIDDEN, PlatformDisconnectedException::CODE, PlatformDisconnectedException::MESSAGE);
        }
        if (ExternalIdentity::MODE_SSO !== $row->getMode()) {
            throw new PlatformSsoException(Response::HTTP_FORBIDDEN, 'not_organisation_login', 'A background key is only for organisation login.');
        }

        return $row;
    }

    private function activeBackgroundKey(ExternalIdentity $row): ?ApiKey
    {
        $key = $this->boundKey($row);

        return $key instanceof ApiKey && $key->isActive() && $this->isBackgroundKey($key) ? $key : null;
    }

    private function boundKey(ExternalIdentity $row): ?ApiKey
    {
        $keyId = $row->getApiKeyId();
        if (null === $keyId) {
            return null;
        }
        $key = $this->apiKeys->find($keyId);

        return $key instanceof ApiKey ? $key : null;
    }

    private function isBackgroundKey(ApiKey $key): bool
    {
        $scopes = array_values($key->getScopes());
        $expected = ApiKeyScope::backgroundKeyScopes();
        sort($scopes);
        sort($expected);

        return $scopes === $expected;
    }

    private function backgroundLabel(PlatformInstance $instance, string $externalId): string
    {
        $app = match ($instance->getClient()) {
            PlatformInstance::CLIENT_NEXTCLOUD => 'Nextcloud',
            PlatformInstance::CLIENT_OWNCLOUD => 'ownCloud.online',
            PlatformInstance::CLIENT_OPENCLOUD => 'OpenCloud',
            default => ucfirst($instance->getClient()),
        };
        $host = preg_replace('#^https?://#', '', $instance->getHost()) ?? $instance->getHost();
        $host = rtrim($host, '/');
        $label = sprintf('%s: %s (%s)%s', $app, $host, $externalId, self::BACKGROUND_LABEL_SUFFIX);
        if (mb_strlen($label) <= self::NAME_MAX) {
            return $label;
        }

        return mb_substr($label, 0, self::NAME_MAX);
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
