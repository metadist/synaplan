<?php

declare(strict_types=1);

namespace App\Service\Auth;

use Symfony\Component\HttpFoundation\Response;

/**
 * Account creation was refused by {@see SignupGuard} or {@see OAuthSignupGate}.
 *
 * Carries a stable reason code the frontend maps to a translated sentence, the
 * HTTP status the endpoint should answer with, and — for a rate limit — when
 * the person may try again.
 */
final class SignupBlockedException extends \RuntimeException
{
    public const CODE_PAUSED = 'SIGNUP_PAUSED';
    public const CODE_RATE_LIMITED = 'SIGNUP_RATE_LIMITED';
    public const CODE_CHALLENGE_REQUIRED = 'SIGNUP_CHALLENGE_REQUIRED';
    public const CODE_ADMISSION_REQUIRED = 'SIGNUP_ADMISSION_REQUIRED';
    public const CODE_CODE_INVALID = 'SIGNUP_CODE_INVALID';
    public const CODE_PLAN_INVALID = 'SIGNUP_PLAN_INVALID';

    private function __construct(
        private readonly string $reasonCode,
        string $message,
        private readonly int $httpStatus,
        private readonly int $retryAfterSeconds,
    ) {
        parent::__construct($message);
    }

    public static function paused(): self
    {
        return new self(
            self::CODE_PAUSED,
            'We are fixing our signup process after some attacks on the platform. Stay tuned.',
            Response::HTTP_SERVICE_UNAVAILABLE,
            0,
        );
    }

    public static function rateLimited(int $retryAfterSeconds): self
    {
        return new self(
            self::CODE_RATE_LIMITED,
            'Too many sign-ups from this connection right now. Please try again in a few minutes.',
            Response::HTTP_TOO_MANY_REQUESTS,
            max(1, $retryAfterSeconds),
        );
    }

    public static function admissionRequired(): self
    {
        return new self(
            self::CODE_ADMISSION_REQUIRED,
            'Choose a plan or enter an invitation code.',
            Response::HTTP_BAD_REQUEST,
            0,
        );
    }

    public static function codeInvalid(): self
    {
        return new self(
            self::CODE_CODE_INVALID,
            'That invitation code cannot be used.',
            Response::HTTP_BAD_REQUEST,
            0,
        );
    }

    public static function planInvalid(): self
    {
        return new self(
            self::CODE_PLAN_INVALID,
            'Choose Pro, Team, or Business.',
            Response::HTTP_BAD_REQUEST,
            0,
        );
    }

    public static function challengeRequired(): self
    {
        return new self(
            self::CODE_CHALLENGE_REQUIRED,
            'Please start the sign-up from the Synaplan website so we can confirm you are not a robot.',
            Response::HTTP_FORBIDDEN,
            0,
        );
    }

    public function getReasonCode(): string
    {
        return $this->reasonCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }

    /** Lower-case form for the OAuth error redirect (`error_code=`). */
    public function getErrorCodeParam(): string
    {
        return strtolower($this->reasonCode);
    }
}
