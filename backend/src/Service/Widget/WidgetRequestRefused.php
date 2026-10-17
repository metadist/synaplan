<?php

declare(strict_types=1);

namespace App\Service\Widget;

/**
 * A public widget request that ends before it does its work, with the JSON
 * answer and the HTTP status the embedded widget reads.
 */
final class WidgetRequestRefused extends \RuntimeException
{
    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(
        public readonly array $payload,
        public readonly int $httpStatus,
    ) {
        parent::__construct(sprintf('Widget request refused (%d)', $httpStatus));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function json(array $payload, int $httpStatus): self
    {
        return new self($payload, $httpStatus);
    }
}
