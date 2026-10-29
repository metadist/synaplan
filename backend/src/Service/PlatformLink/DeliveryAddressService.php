<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\PlatformInstance;
use App\Repository\PlatformInstanceRepository;
use App\Service\Iam\AuditLogWriter;
use App\Service\PlatformLink\Exception\PlatformLinkValidationException;

/**
 * The address Synaplan's servers call. Browsers keep using the registered host.
 */
final readonly class DeliveryAddressService
{
    public function __construct(
        private PlatformInstanceService $instances,
        private PlatformInstanceRepository $platforms,
        private DeliveryOriginPolicy $origins,
        private DeliveryProbe $probe,
        private AuditLogWriter $auditLog,
    ) {
    }

    /**
     * @param list<string>|null $capabilities null leaves the reported features unchanged
     *
     * @return array{delivery_url: string, app_capabilities: list<string>, reachable: ?bool, oidc_client_id: string, app_version: string, server_id: string}
     */
    public function update(
        string $instanceId,
        string $secret,
        ?string $url,
        ?array $capabilities,
        ?string $oidcClientId = null,
        ?string $appVersion = null,
        ?string $serverId = null,
    ): array {
        $instance = $this->instances->requireInstance($instanceId);
        $this->instances->assertSecret($instance, $secret);
        if (null !== $url) {
            $url = trim($url);
            if ('' === $url) {
                $instance->setDeliveryUrl(null);
                $instance->setReachable(null);
            } elseif (!$this->origins->accepts($instance, $url)) {
                throw new PlatformLinkValidationException('That address is not on this cloud.');
            } else {
                $instance->setDeliveryUrl($url);
                $instance->setReachable($this->probe->reachable($url));
            }
        }
        if (null !== $capabilities) {
            $instance->setAppCapabilities($capabilities);
        }
        if (null !== $oidcClientId) {
            $instance->setOidcClientId('' === trim($oidcClientId) ? null : mb_substr(trim($oidcClientId), 0, 191));
            $this->supersedeSameHost($instance);
        }
        if (null !== $appVersion) {
            $instance->setAppVersion('' === trim($appVersion) ? null : mb_substr(trim($appVersion), 0, 32));
        }
        if (null !== $serverId) {
            $instance->setServerId('' === trim($serverId) ? null : mb_substr(trim($serverId), 0, 64));
        }
        $this->platforms->save($instance);

        return [
            'delivery_url' => $instance->getDeliveryUrl() ?? '',
            'app_capabilities' => $instance->getAppCapabilities(),
            'reachable' => $instance->isReachable(),
            'oidc_client_id' => $instance->getOidcClientId() ?? '',
            'app_version' => $instance->getAppVersion() ?? '',
            'server_id' => $instance->getServerId() ?? '',
        ];
    }

    /**
     * A cloud that registers again — after a lost state file, a reinstall —
     * is the same cloud: same host, same OIDC client. Keeping the earlier
     * instance active would leave the bearer's client naming two clouds and
     * every attributed call failing. The newer registration wins; the older
     * one is revoked and the audit log says so.
     */
    private function supersedeSameHost(PlatformInstance $instance): void
    {
        $clientId = $instance->getOidcClientId();
        if (null === $clientId || '' === $clientId || !$instance->isActive()) {
            return;
        }
        foreach ($this->platforms->findBy(['oidcClientId' => $clientId, 'host' => $instance->getHost()]) as $other) {
            if ($other === $instance || $other->getInstanceId() === $instance->getInstanceId() || !$other->isActive()) {
                continue;
            }
            $other->setStatus(PlatformInstance::STATUS_REVOKED);
            $this->platforms->save($other);
            $this->auditLog->record(
                $instance->getRegisteredBy(),
                'platform_instance.superseded',
                'platform_instance',
                $other->getInstanceId(),
                ['by' => $instance->getInstanceId(), 'host' => $instance->getHost()],
            );
        }
    }
}
