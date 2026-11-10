<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\PlatformInstance;
use App\Entity\User;
use App\Repository\ApiKeyRepository;
use App\Repository\ExternalIdentityRepository;
use App\Repository\OAuthTokenRepository;
use App\Repository\PlatformInstanceRepository;
use App\Service\Iam\AuditLogWriter;
use App\Service\PlatformLink\Exception\PlatformInstanceNotFoundException;
use App\Service\PlatformLink\Exception\PlatformLinkValidationException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PlatformInstanceService
{
    public const REGISTER_IP_LIMIT = 10;
    public const REGISTER_IP_WINDOW = 3600;

    /** Matches BEXTERNALIDENTITIES.BEXTERNALID (VARCHAR(191)). */
    public const EXTERNAL_ID_MAX_LENGTH = 191;
    /** Generous cap for the partner's CSRF nonce, which only round-trips. */
    public const STATE_MAX_LENGTH = 512;

    public function __construct(
        private PlatformInstanceRepository $instanceRepository,
        private ExternalIdentityRepository $externalIdentityRepository,
        private ApiKeyRepository $apiKeyRepository,
        private AuditLogWriter $auditLogWriter,
        private EntityManagerInterface $entityManager,
        private DeliveryNotifier $notifier,
        private OAuthTokenRepository $oauthTokens,
    ) {
    }

    /**
     * @return array{client: string, host: string}
     */
    public function describePublic(string $instanceId): array
    {
        $instance = $this->requireInstance($instanceId);
        if (!$instance->isActive()) {
            throw new PlatformInstanceNotFoundException('Instance not found.');
        }

        return [
            'client' => $instance->getClient(),
            'host' => $instance->getHost(),
        ];
    }

    /**
     * @return list<array{id: string, client: string, host: string, status: string, registeredBy: int, created: int, lastSeen: int, deliveryUrl: string, deliveryHost: string, deliveryIp: ?string, reachable: ?bool, appVersion: string, appCapabilities: list<string>, approvedHosts: list<string>}>
     */
    public function listForAdmin(): array
    {
        $out = [];
        foreach ($this->instanceRepository->findAllOrdered() as $instance) {
            $deliveryUrl = $instance->getDeliveryUrl() ?? '';
            $deliveryHost = '' !== $deliveryUrl ? strtolower((string) parse_url($deliveryUrl, PHP_URL_HOST)) : '';
            $deliveryIp = null;
            if ('' !== $deliveryHost && false === filter_var($deliveryHost, \FILTER_VALIDATE_IP)) {
                $resolved = gethostbyname($deliveryHost);
                $deliveryIp = $resolved !== $deliveryHost ? $resolved : null;
            }
            $out[] = [
                'id' => $instance->getInstanceId(),
                'client' => $instance->getClient(),
                'host' => $instance->getHost(),
                'status' => $instance->getStatus(),
                'registeredBy' => $instance->getRegisteredBy(),
                'created' => $instance->getCreated(),
                'lastSeen' => $instance->getLastSeen(),
                'deliveryUrl' => $deliveryUrl,
                'deliveryHost' => $deliveryHost,
                'deliveryIp' => $deliveryIp,
                'reachable' => $instance->isReachable(),
                'appVersion' => $instance->getAppVersion() ?? '',
                'appCapabilities' => $instance->getAppCapabilities(),
                'approvedHosts' => $instance->getApprovedHosts(),
            ];
        }

        return $out;
    }

    /**
     * Approve one private hostname for delivery (02 §6.2). The hostname is
     * stored, not the address it resolves to today.
     */
    public function approveHost(string $instanceId, string $hostname, User $admin, string $ip): void
    {
        $instance = $this->requireInstance($instanceId);
        $host = self::normalizeHostname($hostname);
        $hosts = $instance->getApprovedHosts();
        if (!in_array($host, $hosts, true)) {
            $hosts[] = $host;
            $instance->setApprovedHosts($hosts);
            $this->instanceRepository->save($instance);
        }
        $this->auditLogWriter->record(
            (int) $admin->getId(),
            'platform_instance.host_approved',
            'platform_instance',
            $instanceId,
            ['hostname' => $host],
            $ip,
        );
    }

    public function removeApprovedHost(string $instanceId, string $hostname, User $admin, string $ip): void
    {
        $instance = $this->requireInstance($instanceId);
        $host = self::normalizeHostname($hostname);
        $instance->setApprovedHosts(array_values(array_filter(
            $instance->getApprovedHosts(),
            static fn (string $approved): bool => $approved !== $host,
        )));
        $this->instanceRepository->save($instance);
        $this->auditLogWriter->record(
            (int) $admin->getId(),
            'platform_instance.host_removed',
            'platform_instance',
            $instanceId,
            ['hostname' => $host],
            $ip,
        );
    }

    private static function normalizeHostname(string $hostname): string
    {
        $host = strtolower(trim($hostname));
        if (str_contains($host, '://')) {
            $host = strtolower((string) parse_url($host, PHP_URL_HOST));
        }
        $host = trim($host, '[]');
        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            return $host;
        }
        $host = (string) preg_replace('/:\d+$/', '', $host);
        if (1 !== preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host)) {
            throw new PlatformLinkValidationException('That hostname cannot be approved.');
        }

        return $host;
    }

    public function approve(string $instanceId, User $admin, string $ip): void
    {
        $instance = $this->requireInstance($instanceId);
        if (PlatformInstance::STATUS_PENDING !== $instance->getStatus()) {
            throw new PlatformLinkValidationException('Only pending instances can be approved.');
        }
        $instance->setStatus(PlatformInstance::STATUS_ACTIVE);
        $this->instanceRepository->save($instance);
        $this->auditLogWriter->record(
            (int) $admin->getId(),
            'platform_instance.approved',
            'platform_instance',
            $instanceId,
            ['host' => $instance->getHost()],
            $ip,
        );
    }

    public function revoke(string $instanceId, User $admin, string $ip): void
    {
        $instance = $this->requireInstance($instanceId);
        if (PlatformInstance::OUTLOOK_BUILTIN_ID === $instance->getInstanceId()) {
            throw new PlatformLinkValidationException('The Outlook built-in instance cannot be revoked.');
        }
        $instance->setStatus(PlatformInstance::STATUS_REVOKED);
        $this->instanceRepository->save($instance);
        $this->oauthTokens->revokeAll($instanceId, null);

        $revoked = [];
        foreach ($this->externalIdentityRepository->findByInstanceId($instanceId) as $identity) {
            $revoked[] = $identity;
            $keyId = $identity->getApiKeyId();
            if (null !== $keyId) {
                $key = $this->apiKeyRepository->find($keyId);
                if (null !== $key) {
                    $this->apiKeyRepository->remove($key, false);
                }
            }
            $this->externalIdentityRepository->remove($identity, false);
        }
        $this->entityManager->flush();
        foreach ($revoked as $identity) {
            $this->notifier->identityRevoked($identity);
        }

        $this->auditLogWriter->record(
            (int) $admin->getId(),
            'platform_instance.revoked',
            'platform_instance',
            $instanceId,
            ['host' => $instance->getHost()],
            $ip,
        );
    }

    public function requireInstance(string $instanceId): PlatformInstance
    {
        $instance = $this->instanceRepository->findByInstanceId($instanceId);
        if (!$instance instanceof PlatformInstance) {
            throw new PlatformInstanceNotFoundException('Instance not found.');
        }

        return $instance;
    }
}
