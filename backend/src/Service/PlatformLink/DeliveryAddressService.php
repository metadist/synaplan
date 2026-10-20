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
    ) {
    }

    /**
     * @param list<string>|null $capabilities null leaves the reported features unchanged
     *
     * @return array{delivery_url: string, app_capabilities: list<string>}
     */
    public function update(string $instanceId, string $secret, ?string $url, ?array $capabilities): array
    {
        $instance = $this->instances->requireInstance($instanceId);
        $this->instances->assertSecret($instance, $secret);
        if (null !== $url) {
            $url = trim($url);
            if ('' === $url) {
                $instance->setDeliveryUrl(null);
            } elseif (!$this->origins->accepts($instance, $url)) {
                throw new PlatformLinkValidationException('That address is not on this cloud.');
            } else {
                $instance->setDeliveryUrl($url);
            }
        }
        if (null !== $capabilities) {
            $instance->setAppCapabilities($capabilities);
        }
        $this->platforms->save($instance);

        return [
            'delivery_url' => $instance->getDeliveryUrl() ?? '',
            'app_capabilities' => $instance->getAppCapabilities(),
        ];
    }
}
