<?php

declare(strict_types=1);

namespace App\Service\Iap\Exception;

/**
 * Raised when a receipt/token is present but invalid: bad JWS signature,
 * wrong bundle id / environment, store API rejection, or a malformed payload.
 * Maps to HTTP 400 — the client sent something we cannot trust.
 */
final class IapVerificationException extends IapException
{
}
