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
    public const LOOKUP_FAILED = 'PAY_FIRST_LOOKUP_FAILED';
    public const NOT_FULFILLABLE = 'PAY_FIRST_NOT_FULFILLABLE';

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

    /** After the payment: Stripe could not be read; the caller tries again. */
    public static function lookupFailed(): self
    {
        return new self('Your payment went through. We could not reach the payment service to finish your account; we will try again in a moment.', self::LOOKUP_FAILED, Response::HTTP_SERVICE_UNAVAILABLE);
    }

    /** After the payment: the purchased price is not one this install sells. */
    public static function notFulfillable(): self
    {
        return new self('Your payment went through, but we could not add the plan automatically. We have been alerted and will email you once your account is ready.', self::NOT_FULFILLABLE, Response::HTTP_SERVICE_UNAVAILABLE);
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
