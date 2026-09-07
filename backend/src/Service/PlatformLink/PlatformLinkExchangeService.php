<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\ApiKey;
use App\Entity\ExternalIdentity;
use App\Entity\PlatformInstance;
use App\Entity\User;
use App\Repository\ApiKeyRepository;
use App\Repository\ExternalIdentityRepository;
use App\Repository\PlatformInstanceRepository;
use App\Repository\UserRepository;
use App\Security\ApiKeyScope;
use App\Service\Iam\AuditLogWriter;
use App\Service\PlatformLink\Exception\PlatformInstanceNotActiveException;
use App\Service\PlatformLink\Exception\PlatformLinkCodeException;

/**
 * Server-to-server exchange of a one-time link_code for a scoped API key.
 * Never creates or edits a BUSER row (C3).
 */
final readonly class PlatformLinkExchangeService
{
    public function __construct(
        private PlatformInstanceService $instanceService,
        private LinkCodeService $linkCodeService,
        private UserRepository $userRepository,
        private ApiKeyRepository $apiKeyRepository,
        private ExternalIdentityRepository $externalIdentityRepository,
        private PlatformInstanceRepository $instanceRepository,
        private AuditLogWriter $auditLogWriter,
    ) {
    }

    /**
     * @return array{
     *   success: true,
     *   api_key: array{id: int, key: string, name: string, scopes: list<string>},
     *   user: array{id: int, email: string, display_name: string},
     *   link_id: int
     * }
     */
    public function exchange(string $instanceId, string $instanceSecret, string $code, string $ip): array
    {
        $instance = $this->instanceService->requireInstance($instanceId);
        $this->instanceService->assertSecret($instance, $instanceSecret);
        if (!$instance->isActive()) {
            throw new PlatformInstanceNotActiveException('This platform is waiting for approval by the Synaplan administrator.');
        }

        $payload = $this->linkCodeService->consume($code);
        if (null === $payload || $payload['instanceId'] !== $instanceId) {
            $this->auditLogWriter->record(
                0,
                'platform_link.exchange_failed',
                'platform_instance',
                $instanceId,
                ['reason' => 'unknown_code'],
                $ip,
            );
            throw PlatformLinkCodeException::unknown();
        }

        $user = $this->userRepository->find($payload['userId']);
        if (!$user instanceof User) {
            throw PlatformLinkCodeException::unknown();
        }

        $userCountBefore = $this->userRepository->count([]);
        $mailBefore = $user->getMail();
        $detailsBefore = $user->getUserDetails();

        $existing = $this->externalIdentityRepository->findOneByTriple(
            $instance->getClient(),
            $instance->getInstanceId(),
            $payload['externalId'],
        );
        if ($existing instanceof ExternalIdentity && null !== $existing->getApiKeyId()) {
            $previous = $this->apiKeyRepository->find($existing->getApiKeyId());
            if ($previous instanceof ApiKey) {
                $this->apiKeyRepository->remove($previous);
            }
        }

        $name = $this->keyLabel($instance, $payload['externalId']);
        $scopes = ApiKeyScope::platformLinkScopes($payload['withMemories']);
        $plainKey = 'sk_'.bin2hex(random_bytes(29));

        $apiKey = new ApiKey();
        $apiKey->setOwner($user);
        $apiKey->setKey($plainKey);
        $apiKey->setName($name);
        $apiKey->setStatus('active');
        $apiKey->setScopes($scopes);
        $this->apiKeyRepository->save($apiKey);

        $identity = $this->externalIdentityRepository->upsert(
            (int) $user->getId(),
            $instance->getClient(),
            $payload['externalId'],
            $instance->getInstanceId(),
            (int) $apiKey->getId(),
        );

        $instance->touchLastSeen();

        $this->auditLogWriter->record(
            (int) $user->getId(),
            'platform_link.linked',
            'platform_link',
            (string) $identity->getId(),
            [
                'client' => $instance->getClient(),
                'host' => $instance->getHost(),
                'external_id' => $payload['externalId'],
            ],
            $ip,
        );

        if ($this->userRepository->count([]) !== $userCountBefore
            || $user->getMail() !== $mailBefore
            || $user->getUserDetails() !== $detailsBefore
        ) {
            throw new \LogicException('Linking must not create or edit a Synaplan user.');
        }

        $details = $user->getUserDetails();

        return [
            'success' => true,
            'api_key' => [
                'id' => (int) $apiKey->getId(),
                'key' => $plainKey,
                'name' => $apiKey->getName(),
                'scopes' => $apiKey->getScopes(),
            ],
            'user' => [
                'id' => (int) $user->getId(),
                'email' => (string) $user->getMail(),
                'display_name' => (string) ($details['display_name'] ?? $user->getMail()),
            ],
            'link_id' => (int) $identity->getId(),
        ];
    }

    /**
     * @return list<array{id: int, client: string, host: string, external_id: string, key_id: int|null, key_label: string, created: int, last_seen: int}>
     */
    public function listForUser(int $userId): array
    {
        $out = [];
        foreach ($this->externalIdentityRepository->findByUserId($userId) as $identity) {
            if ('' === $identity->getInstanceId()) {
                continue;
            }
            $instance = $this->instanceRepository->findByInstanceId($identity->getInstanceId());
            if (!$instance instanceof PlatformInstance) {
                continue;
            }
            $keyLabel = '';
            if (null !== $identity->getApiKeyId()) {
                $key = $this->apiKeyRepository->find($identity->getApiKeyId());
                $keyLabel = $key instanceof ApiKey ? $key->getName() : '';
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
            ];
        }

        return $out;
    }

    public function disconnect(int $userId, int $linkId, string $ip): void
    {
        $identity = $this->externalIdentityRepository->find($linkId);
        if (!$identity instanceof ExternalIdentity || $identity->getUserId() !== $userId) {
            throw new Exception\PlatformInstanceNotFoundException('Link not found.');
        }

        if (null !== $identity->getApiKeyId()) {
            $key = $this->apiKeyRepository->find($identity->getApiKeyId());
            if ($key instanceof ApiKey) {
                $this->apiKeyRepository->remove($key, false);
            }
        }
        $this->externalIdentityRepository->remove($identity);

        $this->auditLogWriter->record(
            $userId,
            'platform_link.disconnected',
            'platform_link',
            (string) $linkId,
            [],
            $ip,
        );
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

    private function keyLabel(PlatformInstance $instance, string $externalId): string
    {
        $prefix = match ($instance->getClient()) {
            PlatformInstance::CLIENT_NEXTCLOUD => 'Nextcloud',
            PlatformInstance::CLIENT_OWNCLOUD => 'ownCloud.online',
            default => ucfirst($instance->getClient()),
        };

        return sprintf('%s: %s (%s)', $prefix, $instance->getHost(), $externalId);
    }
}
