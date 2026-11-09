<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\PlatformInstance;
use App\Exception\CloudDeliveryException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A person calling through a connected cloud: the cloud follows from the
 * person's credential, never from a field the request sets.
 */
final readonly class CloudCaller
{
    public function __construct(
        public PlatformInstance $instance,
        public int $userId,
    ) {
    }

    public function instanceId(): string
    {
        return $this->instance->getInstanceId();
    }

    /**
     * Another person's item is answered as if it did not exist, so a caller
     * cannot probe which ids are in use.
     */
    public function requireOwner(int $userId, string $missing): void
    {
        if ($userId !== $this->userId) {
            throw new CloudDeliveryException(Response::HTTP_NOT_FOUND, $missing);
        }
    }
}
