<?php

declare(strict_types=1);

namespace App\Service\Billing;

/**
 * A top-up the API must refuse. The code is stable; the message is for the
 * client that called the endpoint directly.
 */
final class UsageTopupRejected extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }
}
