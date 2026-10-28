<?php

declare(strict_types=1);

namespace App\AI\Pipeline;

use App\AI\Exception\ProviderException;

/**
 * Exception thrown when an {@see AiCall} is refused by a pipeline hook before
 * reaching the provider (`03_contracts.md` §1.2, §1.4).
 *
 * Extends {@see ProviderException} so existing callers catch it with their
 * provider-error handlers while internal fallback loops can distinguish a
 * pre-call policy/limit refusal from a provider transport failure.
 */
final class AiCallRefusedException extends ProviderException
{
    public function __construct(
        public readonly string $refusalReason,
        string $message,
        string $providerName,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $providerName, null, 0, $previous);
    }
}
