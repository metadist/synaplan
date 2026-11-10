<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server;

/**
 * The cloud account cannot be linked to the person who allowed it. The
 * tokens just issued for that attempt are revoked by the caller.
 */
final class CloudLinkRefused extends \RuntimeException
{
}
