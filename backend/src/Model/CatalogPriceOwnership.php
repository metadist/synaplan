<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Decides whether a catalog row's price belongs to LiteLLM or to the catalog.
 *
 * Rule (issue #2309): LiteLLM owns the live per-token price of a catalog row
 * after `app:sync-model-prices` writes it. The catalog owns every other field,
 * and it owns the price again when either:
 *   - the catalog entry sets `json.pricePinned` (a deliberate override the
 *     sync must not overwrite), or
 *   - the catalog price itself changed since the sync recorded
 *     `__catalog_price_at_sync`.
 *
 * An admin edit is a fingerprint mismatch with no LiteLLM owner. Callers
 * (ModelSeeder) keep preserving those rows. This class never treats a bare
 * price difference as a sync.
 *
 * The sync refreshes `__catalog_fingerprint` when it stamps a row, so the
 * seeder does not mistake that write for an admin edit and freeze the row.
 */
final class CatalogPriceOwnership
{
    public const PRICE_OWNER_KEY = '__price_owner';

    public const PRICE_OWNER_LITELLM = 'litellm';

    public const CATALOG_PRICE_AT_SYNC_KEY = '__catalog_price_at_sync';

    /**
     * Catalog json flag. When true, the sync never writes the row and the
     * seeder applies the catalog price.
     */
    public const PRICE_PINNED_KEY = 'pricePinned';

    /**
     * JSON keys the per-token sync may rewrite. They are pricing, not an
     * operator edit of the model's description or features, so a difference
     * here does not by itself mean the row was hand-edited.
     *
     * @var list<string>
     */
    private const PRICE_JSON_KEYS = [
        'pricing_mode',
        'cache_read_price_per_1M',
        'mode_prices',
    ];

    /**
     * @param array<string, mixed>|null $catalogRow
     */
    public static function isPricePinned(?array $catalogRow): bool
    {
        if (null === $catalogRow) {
            return false;
        }

        $json = is_array($catalogRow['json'] ?? null) ? $catalogRow['json'] : [];

        return true === ($json[self::PRICE_PINNED_KEY] ?? false);
    }

    /**
     * Catalog price fields as they were when the sync last accepted LiteLLM.
     * A later edit to any of them is a catalog price change and wins.
     *
     * @param array<string, mixed> $catalogRow
     *
     * @return array{
     *     priceIn: float,
     *     priceOut: float,
     *     inUnit: string,
     *     outUnit: string,
     *     pricing_mode: ?string,
     *     cache_read_price_per_1M: ?float
     * }
     */
    public static function snapshot(array $catalogRow): array
    {
        $json = is_array($catalogRow['json'] ?? null) ? $catalogRow['json'] : [];
        $mode = $json['pricing_mode'] ?? null;
        $cache = $json['cache_read_price_per_1M'] ?? null;

        return [
            'priceIn' => round((float) ($catalogRow['priceIn'] ?? 0.0), ModelCatalog::FINGERPRINT_FLOAT_PRECISION),
            'priceOut' => round((float) ($catalogRow['priceOut'] ?? 0.0), ModelCatalog::FINGERPRINT_FLOAT_PRECISION),
            'inUnit' => (string) ($catalogRow['inUnit'] ?? ''),
            'outUnit' => (string) ($catalogRow['outUnit'] ?? ''),
            'pricing_mode' => is_string($mode) && '' !== $mode ? $mode : null,
            'cache_read_price_per_1M' => is_numeric($cache)
                ? round((float) $cache, ModelCatalog::FINGERPRINT_FLOAT_PRECISION)
                : null,
        ];
    }

    /**
     * True when the live row should keep the LiteLLM price instead of the
     * catalog price. Non-price catalog fields are a separate question.
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $catalog
     */
    public static function shouldKeepLiteLlmPrice(array $existing, array $catalog): bool
    {
        if (self::isPricePinned($catalog)) {
            return false;
        }

        $json = is_array($existing['json'] ?? null) ? $existing['json'] : [];
        if (($json[self::PRICE_OWNER_KEY] ?? null) !== self::PRICE_OWNER_LITELLM) {
            return false;
        }

        $snapshot = $json[self::CATALOG_PRICE_AT_SYNC_KEY] ?? null;
        if (!is_array($snapshot)) {
            return false;
        }

        return self::snapshot($catalog) == self::normalizeSnapshot($snapshot);
    }

    /**
     * True when service, name, tag, provider id, quality, rating and the
     * non-price JSON still match the catalog. Price columns and the keys the
     * sync writes are ignored.
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $catalog
     */
    public static function nonPriceCatalogMatches(array $existing, array $catalog): bool
    {
        return ModelCatalog::fingerprint(self::withoutPrice($existing))
            === ModelCatalog::fingerprint(self::withoutPrice($catalog));
    }

    /**
     * Catalog row with the live LiteLLM price and the sync stamp kept.
     * Used when a release changed a non-price field and the price must stay.
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $catalog
     *
     * @return array<string, mixed>
     */
    public static function mergeKeepingLiteLlmPrice(array $existing, array $catalog): array
    {
        $merged = $catalog;
        $merged['priceIn'] = $existing['priceIn'] ?? 0.0;
        $merged['priceOut'] = $existing['priceOut'] ?? 0.0;
        $merged['inUnit'] = $existing['inUnit'] ?? '';
        $merged['outUnit'] = $existing['outUnit'] ?? '';

        $json = is_array($catalog['json'] ?? null) ? $catalog['json'] : [];
        $existingJson = is_array($existing['json'] ?? null) ? $existing['json'] : [];
        foreach (self::PRICE_JSON_KEYS as $key) {
            if (array_key_exists($key, $existingJson)) {
                $json[$key] = $existingJson[$key];
            } else {
                unset($json[$key]);
            }
        }
        $json[self::PRICE_OWNER_KEY] = self::PRICE_OWNER_LITELLM;
        if (isset($existingJson[self::CATALOG_PRICE_AT_SYNC_KEY]) && is_array($existingJson[self::CATALOG_PRICE_AT_SYNC_KEY])) {
            $json[self::CATALOG_PRICE_AT_SYNC_KEY] = $existingJson[self::CATALOG_PRICE_AT_SYNC_KEY];
        }
        unset($json[ModelCatalog::FINGERPRINT_KEY]);
        $merged['json'] = $json;

        return $merged;
    }

    /**
     * Mark a row as LiteLLM-owned and recompute the catalog fingerprint from
     * the row as it will be stored. The price columns of $row are kept.
     *
     * @param array<string, mixed> $row        catalog-shaped DB row (live prices)
     * @param array<string, mixed> $catalogRow catalog row the snapshot is taken from
     *
     * @return array<string, mixed>
     */
    public static function stamp(array $row, array $catalogRow): array
    {
        $json = is_array($row['json'] ?? null) ? $row['json'] : [];
        unset($json[ModelCatalog::FINGERPRINT_KEY]);
        $json[self::PRICE_OWNER_KEY] = self::PRICE_OWNER_LITELLM;
        // A catalog price that moved since the last stamp must stay visible to
        // the seeder. Overwriting the snapshot with the new catalog price would
        // make the next seed treat that change as already applied.
        $current = self::snapshot($catalogRow);
        $prior = $json[self::CATALOG_PRICE_AT_SYNC_KEY] ?? null;
        $json[self::CATALOG_PRICE_AT_SYNC_KEY] = is_array($prior) && $current != self::normalizeSnapshot($prior)
            ? self::normalizeSnapshot($prior)
            : $current;
        $row['json'] = $json;
        $json[ModelCatalog::FINGERPRINT_KEY] = ModelCatalog::fingerprint($row);
        $row['json'] = $json;

        return $row;
    }

    /**
     * A row the sync already owns whose fingerprint is missing or stale, and
     * whose catalog price has not moved. Restamping must not hide a catalog
     * price change: when the snapshot differs, the seeder has to apply it.
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $catalog
     */
    public static function needsOwnershipStamp(array $existing, array $catalog): bool
    {
        if (self::isPricePinned($catalog) || !self::nonPriceCatalogMatches($existing, $catalog)) {
            return false;
        }

        $json = is_array($existing['json'] ?? null) ? $existing['json'] : [];
        $snapshot = $json[self::CATALOG_PRICE_AT_SYNC_KEY] ?? null;
        if (($json[self::PRICE_OWNER_KEY] ?? null) === self::PRICE_OWNER_LITELLM && is_array($snapshot)) {
            if (self::snapshot($catalog) != self::normalizeSnapshot($snapshot)) {
                return false;
            }
        }

        $stamped = self::stamp($existing, $catalog);
        $stampedJson = is_array($stamped['json'] ?? null) ? $stamped['json'] : [];

        return ($json[ModelCatalog::FINGERPRINT_KEY] ?? null) !== ($stampedJson[ModelCatalog::FINGERPRINT_KEY] ?? null)
            || ($json[self::PRICE_OWNER_KEY] ?? null) !== self::PRICE_OWNER_LITELLM;
    }

    /**
     * @param array<string, mixed> $snapshot
     *
     * @return array{
     *     priceIn: float,
     *     priceOut: float,
     *     inUnit: string,
     *     outUnit: string,
     *     pricing_mode: ?string,
     *     cache_read_price_per_1M: ?float
     * }
     */
    private static function normalizeSnapshot(array $snapshot): array
    {
        $mode = $snapshot['pricing_mode'] ?? null;
        $cache = $snapshot['cache_read_price_per_1M'] ?? null;

        return [
            'priceIn' => round((float) ($snapshot['priceIn'] ?? 0.0), ModelCatalog::FINGERPRINT_FLOAT_PRECISION),
            'priceOut' => round((float) ($snapshot['priceOut'] ?? 0.0), ModelCatalog::FINGERPRINT_FLOAT_PRECISION),
            'inUnit' => (string) ($snapshot['inUnit'] ?? ''),
            'outUnit' => (string) ($snapshot['outUnit'] ?? ''),
            'pricing_mode' => is_string($mode) && '' !== $mode ? $mode : null,
            'cache_read_price_per_1M' => is_numeric($cache)
                ? round((float) $cache, ModelCatalog::FINGERPRINT_FLOAT_PRECISION)
                : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function withoutPrice(array $row): array
    {
        $copy = $row;
        $copy['priceIn'] = 0.0;
        $copy['priceOut'] = 0.0;
        $copy['inUnit'] = '';
        $copy['outUnit'] = '';

        $json = is_array($copy['json'] ?? null) ? $copy['json'] : [];
        unset(
            $json[ModelCatalog::FINGERPRINT_KEY],
            $json[self::PRICE_OWNER_KEY],
            $json[self::CATALOG_PRICE_AT_SYNC_KEY],
            $json[self::PRICE_PINNED_KEY],
        );
        foreach (self::PRICE_JSON_KEYS as $key) {
            unset($json[$key]);
        }
        $copy['json'] = $json;

        return $copy;
    }
}
