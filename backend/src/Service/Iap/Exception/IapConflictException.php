<?php

declare(strict_types=1);

namespace App\Service\Iap\Exception;

/**
 * Raised when a purchase cannot be granted because of an ownership conflict:
 * the subscription is already owned by another channel (block-cross), or the
 * receipt was already redeemed by a different user (replay / shared receipt).
 * Maps to HTTP 409.
 */
final class IapConflictException extends IapException
{
}
