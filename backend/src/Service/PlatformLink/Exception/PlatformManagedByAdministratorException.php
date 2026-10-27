<?php

declare(strict_types=1);

namespace App\Service\PlatformLink\Exception;

/**
 * An administrator created this connection. The person cannot disconnect it.
 */
final class PlatformManagedByAdministratorException extends \RuntimeException
{
    public const CODE = 'managed_by_administrator';

    public const MESSAGE = 'An administrator created this connection. Ask them to turn it off.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
