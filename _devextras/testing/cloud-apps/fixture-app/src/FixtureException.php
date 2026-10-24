<?php

declare(strict_types=1);

namespace CloudFixture;

/**
 * A request the fixture refuses, with the HTTP status and a short code the
 * scripts can match on.
 */
final class FixtureException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
