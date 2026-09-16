<?php

declare(strict_types=1);

namespace App\Service\Desktop\Exception;

/**
 * Thrown when a user hits a pairing-code rate limit (too many outstanding
 * codes, or too many created within the hour). The controller maps this to a
 * 429 without leaking which limit tripped beyond the message.
 */
final class PairingLimitException extends \RuntimeException
{
}
