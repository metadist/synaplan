<?php

declare(strict_types=1);

namespace App\Service\Security;

/**
 * A request or one of its redirects pointed at an address {@see SsrfGuard}
 * refuses. Nothing was sent to that address.
 */
final class BlockedAddressException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('URL points to a private/blocked address');
    }
}
