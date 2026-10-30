<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Repository\PlatformInstanceRepository;
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
        private PlatformInstanceSupersession $supersession,
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
            $this->supersession->takeOverOlderTwins($instance);
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
}
