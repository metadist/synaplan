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
     * Release dates that all fall within this span are request-time or
     * placeholder stamps, not release dates: Mistral stamps every row with
     * the time of the request, Meta with 0.
     */
    private const MIN_RELEASE_DATE_SPREAD_SECONDS = 86400;

    /**
     * @param list<string>       $modelIds   lowercased provider-side model ids
     * @param array<string, int> $releasedAt model id => provider release date (unix seconds)
     */
    private function __construct(
        public string $status,
        public array $modelIds,
        public ?string $detail,
        public array $releasedAt = [],
    ) {
    }

    /**
     * @param list<string>       $modelIds
     * @param array<string, int> $releasedAt model id => provider release date (unix seconds), when the listing has one
     */
    public static function ok(array $modelIds, array $releasedAt = []): self
    {
        $normalised = [];
        foreach ($modelIds as $id) {
            $id = strtolower(trim($id));
            if ('' !== $id) {
                $normalised[$id] = true;
            }
        }

        $dates = [];
        foreach ($releasedAt as $id => $timestamp) {
            $id = strtolower(trim((string) $id));
            if (isset($normalised[$id]) && $timestamp > 0) {
                $dates[$id] = $timestamp;
            }
        }

        return new self(self::STATUS_OK, array_keys($normalised), null, $dates);
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

    /**
     * Ids the provider says it released at or after $since.
     *
     * Empty when the listing has no usable release dates — see
     * {@see self::MIN_RELEASE_DATE_SPREAD_SECONDS}. Trusting those stamps
     * would make every model of the provider look brand new.
     *
     * @return list<string>
     */
    public function releasedSince(\DateTimeImmutable $since): array
    {
        if (count($this->releasedAt) < 2
            || max($this->releasedAt) - min($this->releasedAt) < self::MIN_RELEASE_DATE_SPREAD_SECONDS) {
            return [];
        }

        $threshold = $since->getTimestamp();
        $ids = [];
        foreach ($this->releasedAt as $id => $timestamp) {
            if ($timestamp >= $threshold) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }
}
