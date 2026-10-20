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
        public bool $pending = false,
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

    /**
     * The copy was accepted and is still being written in the cloud.
     * Only the platform destination returns this.
     *
     * @param array<string, string> $context
     */
    public static function pending(array $context): self
    {
        return new self(true, null, null, $context, true);
    }

    public function isPending(): bool
    {
        return $this->pending;
    }
}
