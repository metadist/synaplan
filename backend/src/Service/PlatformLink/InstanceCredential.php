<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\PlatformInstance;
use App\Service\PlatformLink\Exception\PlatformInstanceNotActiveException;
use App\Service\PlatformLink\Exception\PlatformInstanceSecretException;

/**
 * The instance credential (X-Instance-Id + X-Instance-Secret) an app sends
 * beside a person's token or key.
 */
final readonly class InstanceCredential
{
    public function __construct(
        private PlatformInstanceService $instances,
    ) {
    }

    public function require(string $instanceId, string $secret): PlatformInstance
    {
        if ('' === trim($instanceId) || '' === $secret) {
            throw new PlatformInstanceSecretException('Invalid instance credentials.');
        }

        $instance = $this->instances->requireInstance(trim($instanceId));
        $this->instances->assertSecret($instance, $secret);
        if (!$instance->isActive()) {
            throw new PlatformInstanceNotActiveException('This platform is waiting for approval by the Synaplan administrator.');
        }

        return $instance;
    }
}
