<?php

declare(strict_types=1);

namespace App\Service\ModelDiscovery;

/**
 * Comparison helpers for matching provider listing ids against BMODELS.
 *
 * Keys are for comparison only — never shown as a suggested provider id.
 *
 * Matching is asymmetric on date snapshots:
 * - {@see normalize()} — lowercase, trim, strip Google's `models/` prefix only.
 * - {@see undated()} — strip a trailing `-YYYYMMDD` / `-YYYY-MM-DD` from a
 *   normalised id.
 * - A listed id is known iff its normalised form is in the exact known set,
 *   or an undated catalog row aliases a dated listing, or an undated listing
 *   aliases a date-pinned catalog row. A new dated snapshot of a date-pinned
 *   catalog row stays pending (human decision).
 */
final class ModelDiscoveryIdNormalizer
{
    /**
     * Lowercase, trim, strip Google's `models/` prefix. Does not strip dates.
     */
    public static function normalize(string $id): string
    {
        $key = strtolower(trim($id));
        if (str_starts_with($key, 'models/')) {
            $key = substr($key, strlen('models/'));
        }

        return $key;
    }

    /**
     * Strip a trailing date snapshot suffix from an already-normalised id.
     */
    public static function undated(string $normalizedId): string
    {
        $key = preg_replace('/-\d{8}$/', '', $normalizedId) ?? $normalizedId;
        $key = preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', $key) ?? $key;

        return $key;
    }

    /**
     * Whether a listed upstream id is already represented in BMODELS.
     *
     * @param array<string, true> $exactKnown normalize() of every BPROVID /
     *                                        BJSON.params.model for the provider
     */
    public static function isKnown(string $listedId, array $exactKnown): bool
    {
        $n = self::normalize($listedId);
        if (isset($exactKnown[$n])) {
            return true;
        }

        $u = self::undated($n);
        if ($u !== $n && isset($exactKnown[$u])) {
            return true;
        }

        if ($u === $n) {
            foreach (array_keys($exactKnown) as $exact) {
                if (self::undated($exact) === $n) {
                    return true;
                }
            }
        }

        return false;
    }
}
