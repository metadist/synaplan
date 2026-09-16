<?php

declare(strict_types=1);

namespace App\Service\Destination;

final readonly class DestinationResult
{
    /**
     * @param array<string, string> $context interpolated names for i18n ({connection}, {target}, {newName})
     */
    private function __construct(
        public bool $ok,
        public ?string $reference,
        public ?DestinationFailureCode $code,
        public array $context,
    ) {
    }

    /**
     * @param array<string, string> $context
     */
    public static function success(string $reference, array $context = []): self
    {
        return new self(true, $reference, null, $context);
    }

    /**
     * @param array<string, string> $context
     */
    public static function failure(DestinationFailureCode $code, array $context = []): self
    {
        return new self(false, null, $code, $context);
    }
}
