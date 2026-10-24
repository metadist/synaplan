<?php

declare(strict_types=1);

namespace App\Service\PlatformLink\Exception;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * The person's identity on this cloud is paused or revoked (02 §6.3).
 */
final class PlatformDisconnectedException extends AuthenticationException
{
    public const CODE = 'platform_disconnected';

    public const MESSAGE = 'You turned Synaplan off for this app in Synaplan.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
