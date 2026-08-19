<?php

declare(strict_types=1);

namespace App\AI\Service;

/**
 * The result of asking one provider which models it currently serves.
 *
 * The distinction between {@see STATUS_OK} and every other status is the whole
 * point of this class. A caller may only conclude "this model is gone" when the
 * status is OK; an unreachable API, a missing key or a provider without a
 * listing endpoint must never be read as an empty catalog, because that would
 * report every model of that provider as discontinued at once.
 */
final readonly class ProviderModelListing
{
    public const STATUS_OK = 'ok';

    /** The provider exposes no endpoint that enumerates served models. */
    public const STATUS_NO_LISTING_ENDPOINT = 'no_listing_endpoint';

    /** No API key is configured for this provider on this install. */
    public const STATUS_NOT_CONFIGURED = 'not_configured';

    /** The API could not be reached, rejected us, or returned nothing usable. */
    public const STATUS_UNREACHABLE = 'unreachable';

    /**
     * @param list<string> $modelIds lowercased provider-side model ids
     */
    private function __construct(
        public string $status,
        public array $modelIds,
        public ?string $detail,
    ) {
    }

    /**
     * @param list<string> $modelIds
     */
    public static function ok(array $modelIds): self
    {
        $normalised = [];
        foreach ($modelIds as $id) {
            $id = strtolower(trim($id));
            if ('' !== $id) {
                $normalised[$id] = true;
            }
        }

        return new self(self::STATUS_OK, array_keys($normalised), null);
    }

    public static function noListingEndpoint(string $detail): self
    {
        return new self(self::STATUS_NO_LISTING_ENDPOINT, [], $detail);
    }

    public static function notConfigured(): self
    {
        return new self(self::STATUS_NOT_CONFIGURED, [], 'No API key configured.');
    }

    public static function unreachable(string $detail): self
    {
        return new self(self::STATUS_UNREACHABLE, [], $detail);
    }

    /**
     * True only when the returned list can be trusted as complete enough to
     * conclude that an absent model is really absent.
     */
    public function isConclusive(): bool
    {
        return self::STATUS_OK === $this->status;
    }

    public function serves(string $providerModelId): bool
    {
        return in_array(strtolower(trim($providerModelId)), $this->modelIds, true);
    }
}
