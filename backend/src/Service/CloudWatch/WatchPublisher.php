<?php

declare(strict_types=1);

namespace App\Service\CloudWatch;

use App\Entity\CloudWatch;
use App\Entity\ExternalIdentity;
use App\Entity\PlatformInstance;
use App\Repository\PlatformInstanceRepository;
use App\Service\File\CloudFileStatusCache;
use App\Service\PlatformLink\DeliveryNotifier;

/**
 * Tells the cloud that its watch index changed, and drops the person's
 * cached file-state answer.
 */
final readonly class WatchPublisher
{
    public function __construct(
        private DeliveryNotifier $notifier,
        private PlatformInstanceRepository $platforms,
        private CloudFileStatusCache $cache,
    ) {
    }

    public function changed(CloudWatch $watch, ExternalIdentity $identity): void
    {
        $this->cache->bumpUser($identity->getUserId());
        $watchId = $watch->getId();
        $instance = $this->platforms->findByInstanceId($identity->getInstanceId());
        if (null === $watchId || !$instance instanceof PlatformInstance) {
            return;
        }
        $this->notifier->watchesChanged($instance, $watchId);
    }
}
