<?php

declare(strict_types=1);

namespace App\Plug;

/**
 * Health of one adapter at a point in time. Shape mirrors
 * {@see \App\AI\Interface\ProviderMetadataInterface::getStatus()}.
 */
final readonly class PlugHealth
{
    public function __construct(
        public bool $available,
        public ?string $reason,
        public int $checkedAt,
    ) {
    }

    public static function available(): self
    {
        return new self(true, null, time());
    }

    public static function unavailable(string $reason): self
    {
        return new self(false, $reason, time());
    }
}
