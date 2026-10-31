<?php

declare(strict_types=1);

namespace App\AI\Catalog;

/**
 * How `app:models:sync-prices` looks a provider's models up in the LiteLLM
 * price list: under explicit key prefixes (tried in order, the first hit
 * wins), under the service name, or not at all for engines that bill
 * nothing. A bare model id (no prefix) only counts when LiteLLM attributes
 * it to one of the provider's own vendor names.
 */
final readonly class ProviderPricing
{
    /**
     * @param list<string> $litellmPrefixes  tried in this order; empty = the service name
     * @param list<string> $bareMatchVendors `litellm_provider` values (prefix match) that are this vendor;
     *                                       empty = a bare id never matches and lands in `unmatched`
     */
    public function __construct(
        public bool $synced,
        public array $litellmPrefixes,
        public array $bareMatchVendors = [],
    ) {
    }

    /**
     * An explicit prefix list, e.g. `gemini` then `google` for Google.
     *
     * @param list<string> $prefixes
     * @param list<string> $bareMatchVendors
     */
    public static function litellm(array $prefixes, array $bareMatchVendors = []): self
    {
        return new self(true, $prefixes, $bareMatchVendors);
    }

    /** The default: LiteLLM keys are tried under the service name itself. */
    public static function byServiceName(): self
    {
        return new self(true, []);
    }

    /** Local engines and providers with no public price list are skipped. */
    public static function none(): self
    {
        return new self(false, []);
    }

    /**
     * The prefixes the sync command tries for a service name, in order.
     *
     * @return list<string>
     */
    public function prefixesFor(string $service): array
    {
        return [] === $this->litellmPrefixes ? [strtolower($service)] : $this->litellmPrefixes;
    }
}
