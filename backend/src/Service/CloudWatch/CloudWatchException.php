<?php

declare(strict_types=1);

namespace App\Service\CloudWatch;

/**
 * A watch call that the person can be told about. The message is the sentence.
 */
final class CloudWatchException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly string $error = 'invalid_watch',
    ) {
        parent::__construct($message);
    }
}
