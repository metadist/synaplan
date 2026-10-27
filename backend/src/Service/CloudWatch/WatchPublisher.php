<?php

declare(strict_types=1);

namespace App\Service\CloudWatch;

use App\Entity\CloudWatch;
use App\Entity\ExternalIdentity;
use App\Service\File\CloudFileStatusCache;

/**
 * Drops the person's cached file-state answer and remembers that the cloud
 * should be told. The notice itself goes out after the response is committed.
 */
final readonly class WatchPublisher
{
    public function __construct(
        private CloudFileStatusCache $cache,
        private WatchChangeQueue $queue,
    ) {
    }

    public function changed(CloudWatch $watch, ExternalIdentity $identity): void
    {
        if (null === $watch->getId()) {
            return;
        }
        $this->cache->bumpUser($identity->getUserId());
        $this->queue->add($identity->getInstanceId());
    }
}
