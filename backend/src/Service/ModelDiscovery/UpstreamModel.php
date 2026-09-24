<?php

declare(strict_types=1);

namespace App\Service\ModelDiscovery;

/**
 * One model entry from an upstream listing (e.g. OpenRouter).
 *
 * Prices are USD per 1M tokens when the source published a per-token rate;
 * null means the source omitted that field.
 */
final readonly class UpstreamModel
{
    public function __construct(
        public string $openRouterId,
        public string $vendor,
        public \DateTimeImmutable $created,
        public ?float $priceInPer1M,
        public ?float $priceOutPer1M,
        public ?float $cacheReadPer1M,
    ) {
    }
}
