<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Api\Exception;

/**
 * Raised when FastBill returns an error response (HTTP error status or an
 * ERRORS array in the JSON body). Carries the service name and the FastBill
 * error messages for context — but never the credentials.
 */
final class FastBillApiException extends FastBillException
{
    /**
     * @param array<int, string> $errors
     */
    public function __construct(
        public readonly string $service,
        public readonly array $errors,
        public readonly int $statusCode,
    ) {
        $detail = [] === $errors ? 'unknown error' : implode('; ', $errors);
        parent::__construct(sprintf('FastBill service "%s" failed (HTTP %d): %s', $service, $statusCode, $detail));
    }

    public function isRateLimited(): bool
    {
        return 429 === $this->statusCode;
    }

    public function isAuthenticationError(): bool
    {
        if (401 === $this->statusCode) {
            return true;
        }
        foreach ($this->errors as $error) {
            if (false !== stripos($error, 'login error') || false !== stripos($error, 'credentials')) {
                return true;
            }
        }

        return false;
    }
}
