<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\PlatformInstance;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A connected cloud that proved itself with its instance credential. It is
 * never a person: routes behind ROLE_PLATFORM_INSTANCE are the only ones it
 * reaches.
 */
final readonly class PlatformInstancePrincipal implements UserInterface
{
    public const ROLE = 'ROLE_PLATFORM_INSTANCE';

    public function __construct(
        public PlatformInstance $instance,
    ) {
    }

    public function getRoles(): array
    {
        return [self::ROLE];
    }

    public function getUserIdentifier(): string
    {
        return $this->instance->getInstanceId();
    }
}
