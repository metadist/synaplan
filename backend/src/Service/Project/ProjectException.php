<?php

declare(strict_types=1);

namespace App\Service\Project;

/**
 * A request about a project that cannot be done. The code is the HTTP status
 * the controller answers with.
 */
final class ProjectException extends \RuntimeException
{
    public const NOT_FOUND = 404;
    public const INVALID = 422;
    public const LIMIT = 409;
    public const RATE_LIMITED = 429;
    public const UPSTREAM = 502;

    public static function notFound(string $what): self
    {
        return new self($what.' not found', self::NOT_FOUND);
    }

    public static function invalid(string $message): self
    {
        return new self($message, self::INVALID);
    }

    public static function limit(string $message): self
    {
        return new self($message, self::LIMIT);
    }

    public static function rateLimited(string $message): self
    {
        return new self($message, self::RATE_LIMITED);
    }

    public static function upstream(string $message): self
    {
        return new self($message, self::UPSTREAM);
    }
}
