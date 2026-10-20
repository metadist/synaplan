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

    public function update(string $instanceId, string $secret, string $url): string
    {
        $instance = $this->instances->requireInstance($instanceId);
        $this->instances->assertSecret($instance, $secret);
        $url = trim($url);
        if ('' === $url) {
            $instance->setDeliveryUrl(null);
        } elseif (!$this->origins->accepts($instance, $url)) {
            throw new PlatformLinkValidationException('That address is not on this cloud.');
        } else {
            $instance->setDeliveryUrl($url);
        }
        $this->platforms->save($instance);

        return $instance->getDeliveryUrl() ?? '';
    }
}
