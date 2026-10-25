<?php

declare(strict_types=1);

namespace App\Service\Billing\PayFirst;

use Symfony\Component\HttpFoundation\Response;

/**
 * A pay-first checkout that cannot start. The code is stable for the
 * frontend, which maps it to one sentence; the message is for logs and API
 * clients.
 */
final class PayFirstException extends \RuntimeException
{
    public const EMAIL_INVALID = 'PAY_FIRST_EMAIL_INVALID';
    public const PLAN_INVALID = 'PAY_FIRST_PLAN_INVALID';
    public const RATE_LIMITED = 'PAY_FIRST_RATE_LIMITED';
    public const UNAVAILABLE = 'PAY_FIRST_UNAVAILABLE';

    private function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $httpStatus,
        private readonly int $retryAfterSeconds = 0,
    ) {
        parent::__construct($message);
    }

    public static function emailInvalid(): self
    {
        return new self('Enter a valid email address.', self::EMAIL_INVALID, Response::HTTP_BAD_REQUEST);
    }

    public static function planInvalid(): self
    {
        return new self('That plan is not available.', self::PLAN_INVALID, Response::HTTP_BAD_REQUEST);
    }

    public static function rateLimited(int $retryAfterSeconds): self
    {
        return new self('Too many attempts. Please wait a few minutes and try again.', self::RATE_LIMITED, Response::HTTP_TOO_MANY_REQUESTS, max(1, $retryAfterSeconds));
    }

    public static function unavailable(): self
    {
        return new self('The payment page could not be opened. Nothing was charged; please try again in a minute.', self::UNAVAILABLE, Response::HTTP_BAD_GATEWAY);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
