<?php

declare(strict_types=1);

namespace App\AI\Pipeline;

use App\AI\Exception\ProviderException;

/**
 * Refusal returned by {@see AiCallHookInterface::before()} when a call is not
 * permitted to reach the provider (`03_contracts.md` §1.2, §1.4).
 */
final readonly class AiCallRefusal
{
    public function __construct(
        public string $reason,
        public string $message,
        public string $provider,
        public ?\Throwable $exception = null,
    ) {
    }

    public static function policy(string $provider, string $message = 'AI request refused by policy.'): self
    {
        return new self(
            reason: 'policy',
            message: $message,
            provider: $provider,
            exception: new ProviderException($message, $provider),
        );
    }

    public static function circuitOpen(string $provider, ProviderException $exception): self
    {
        return new self(
            reason: 'circuit_open',
            message: $exception->getMessage(),
            provider: $provider,
            exception: $exception,
        );
    }

    public static function rateLimit(string $provider, string $message = 'Rate limit exceeded.'): self
    {
        return new self(
            reason: 'rate_limit',
            message: $message,
            provider: $provider,
            exception: new ProviderException($message, $provider),
        );
    }

    public function toThrowable(): \Throwable
    {
        return $this->exception ?? new ProviderException($this->message, $this->provider);
    }
}
