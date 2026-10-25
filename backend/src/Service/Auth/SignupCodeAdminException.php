<?php

declare(strict_types=1);

namespace App\Service\Auth;

/**
 * A sign-up code request an administrator sent that cannot be applied. The
 * error code is stable for the frontend; the message is for logs and the API.
 */
final class SignupCodeAdminException extends \RuntimeException
{
    public const NOT_FOUND = 'SIGNUP_CODE_NOT_FOUND';
    public const INVALID = 'SIGNUP_CODE_REQUEST_INVALID';

    private function __construct(string $message, private readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function notFound(int $id): self
    {
        return new self(sprintf('Sign-up code %d does not exist.', $id), self::NOT_FOUND);
    }

    public static function invalid(string $message): self
    {
        return new self($message, self::INVALID);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
