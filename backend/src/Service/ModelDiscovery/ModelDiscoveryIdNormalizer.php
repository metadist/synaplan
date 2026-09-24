<?php

declare(strict_types=1);

namespace App\Service\ModelDiscovery;

/**
 * Comparison key for matching provider listing ids against BMODELS.
 *
 * Matching keys are for comparison only — never shown as a suggested provider id.
 * Both sides of a match (upstream listing and BPROVID / BJSON.params.model) must
 * pass through the same normalisation.
 */
final class ModelDiscoveryIdNormalizer
{
    /**
     * Lowercase, strip Google's `models/` prefix, strip trailing date snapshots
     * (`-YYYYMMDD` or `-YYYY-MM-DD`) so a new dated snapshot of a known model
     * counts as known.
     */
    public static function normalize(string $id): string
    {
        $key = strtolower(trim($id));
        if (str_starts_with($key, 'models/')) {
            $key = substr($key, strlen('models/'));
        }

        $key = preg_replace('/-\d{8}$/', '', $key) ?? $key;
        $key = preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', $key) ?? $key;

        return $key;
    }
}
