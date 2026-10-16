<?php

declare(strict_types=1);

namespace App\AI\Import;

/**
 * Price published by an OpenAI-compatible /models row.
 *
 * OpenRouter (and other hosted gateways) send `pricing.prompt` and
 * `pricing.completion` as USD **per token** strings. BMODELS stores USD per
 * 1M tokens, the same unit as the catalog and the README. A missing or
 * non-numeric price is unknown — not free. A published 0 is free.
 */
final readonly class ListedModelPrice
{
    private function __construct(
        public bool $known,
        public ?float $priceInPerMillion,
        public ?float $priceOutPerMillion,
    ) {
    }

    public static function unknown(): self
    {
        return new self(false, null, null);
    }

    public static function free(): self
    {
        return new self(true, 0.0, 0.0);
    }

    /**
     * @param array<string, mixed> $item one object from `GET /models` `data`
     */
    public static function fromListingItem(array $item): self
    {
        $pricing = $item['pricing'] ?? null;
        if (!is_array($pricing)) {
            return self::unknown();
        }

        $priceIn = self::perMillion($pricing['prompt'] ?? null);
        $priceOut = self::perMillion($pricing['completion'] ?? null);
        if (null === $priceIn || null === $priceOut) {
            return self::unknown();
        }

        return new self(true, $priceIn, $priceOut);
    }

    private static function perMillion(mixed $perToken): ?float
    {
        if (is_string($perToken)) {
            $perToken = trim($perToken);
        }
        if (!is_int($perToken) && !is_float($perToken) && !is_string($perToken)) {
            return null;
        }
        if (is_string($perToken) && !is_numeric($perToken)) {
            return null;
        }

        $value = (float) $perToken;
        if ($value < 0.0 || !is_finite($value)) {
            return null;
        }

        return $value * 1_000_000;
    }
}
