<?php

declare(strict_types=1);

namespace App\Service\PlatformLink\Exception;

/**
 * Unknown, expired, replayed, or foreign-instance codes share this exception
 * so the HTTP layer can return one message (no enumeration).
 */
final class PlatformLinkCodeException extends \RuntimeException
{
    public static function unknown(): self
    {
        return new self('Invalid or expired link code.');
    }
}
