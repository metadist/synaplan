<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * A new digest row could not be inserted because every candidate primary key
 * already belonged to a different (user, message) row.
 */
final class MessageDigestIdCollisionException extends \RuntimeException
{
    public function __construct(
        public readonly int $userId,
        public readonly int $messageId,
        public readonly int $attempts,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(sprintf(
            'Could not store digest for user %d message %d after %d primary-key attempts',
            $userId,
            $messageId,
            $attempts,
        ), 0, $previous);
    }
}
