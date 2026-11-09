<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Why an instance credential was refused, with the status the platform apps
 * already act on: 404 unknown instance, 401 wrong secret, 403 waiting for
 * approval, 429 too many failed attempts.
 */
final class PlatformInstanceAuthenticationException extends AuthenticationException
{
    private function __construct(
        public readonly int $status,
        public readonly string $error,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function unknownInstance(): self
    {
        return new self(Response::HTTP_NOT_FOUND, 'unknown_instance', 'Instance not found.');
    }

    public static function invalidSecret(): self
    {
        return new self(Response::HTTP_UNAUTHORIZED, 'unauthorized', 'Invalid instance credentials.');
    }

    public static function pending(): self
    {
        return new self(Response::HTTP_FORBIDDEN, 'instance_pending', 'This platform is waiting for approval by the Synaplan administrator.');
    }

    public static function throttled(): self
    {
        return new self(Response::HTTP_TOO_MANY_REQUESTS, 'too_many_attempts', 'Too many failed instance credentials from this address.');
    }

    public function getMessageKey(): string
    {
        return $this->getMessage();
    }
}
