<?php

declare(strict_types=1);

namespace App\Service\Compute;

/**
 * Sidecar refused the request with a protocol error code (problem+json).
 */
final class ComputeRefusedException extends \RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly mixed $details = null,
        int $httpStatus = 400,
    ) {
        parent::__construct($message, $httpStatus);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function details(): mixed
    {
        return $this->details;
    }

    /**
     * True when the sidecar had no room for another run. A full persistent
     * folder is not this — it has its own sentence in the runner.
     */
    public function isQuota(): bool
    {
        return 'capacity_exceeded' === $this->errorCode;
    }
}
