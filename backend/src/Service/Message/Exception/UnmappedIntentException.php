<?php

declare(strict_types=1);

namespace App\Service\Message\Exception;

/**
 * Raised when an intent has no message-router handler.
 *
 * Replaces the old `$handlerMap[$intent] ?? 'chat'` default: an intent that is
 * not a {@see \App\Service\Multitask\Plan\Capability}, or a capability the
 * legacy router does not dispatch, fails instead of becoming a chat turn that
 * only claims the work was done (#1915, #1972 step 1).
 */
final class UnmappedIntentException extends \RuntimeException
{
    public function __construct(string $intent)
    {
        parent::__construct(sprintf('No message handler for intent "%s".', $intent));
    }
}
