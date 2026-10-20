<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A claim, download or result the cloud app must not proceed with.
 */
final class CloudDeliveryException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
    ) {
        parent::__construct($errorCode);
    }
}
