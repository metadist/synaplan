<?php

declare(strict_types=1);

namespace App\Service\Dav;

use App\Service\Destination\DestinationFailureCode;

/**
 * A failed DAV request. The message never contains the credential; the HTTP
 * status carries enough to map onto the shared destination failure vocabulary.
 */
final class DavException extends \RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function toFailureCode(): DestinationFailureCode
    {
        return match (true) {
            401 === $this->statusCode, 403 === $this->statusCode => DestinationFailureCode::Unauthorized,
            404 === $this->statusCode, 409 === $this->statusCode => DestinationFailureCode::NotFound,
            413 === $this->statusCode => DestinationFailureCode::TooLarge,
            429 === $this->statusCode => DestinationFailureCode::RateLimited,
            507 === $this->statusCode => DestinationFailureCode::QuotaExceeded,
            default => DestinationFailureCode::Unreachable,
        };
    }
}
