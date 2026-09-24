<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Reviewed decisions to skip specific upstream model ids in new-model discovery.
 *
 * Keys are exact `provider:id` strings (inventory provider key + the provider's
 * own listing id, lowercased). Never a pattern or prefix. Each entry records why
 * we skip the id and when that decision was made. Delete an entry when the id
 * disappears upstream or lands in this install's BMODELS.
 *
 * Starts empty: the per-provider baseline absorbs today's listings on first run.
 *
 * @see docs/PRICING_MAINTENANCE.md §"New model detection"
 */
final class ModelDiscoveryIgnoreList
{
    /**
     * @var array<string, array{reason: string, decidedOn: string}>
     */
    public const ENTRIES = [
        // Example shape (do not leave placeholders here):
        // 'openai:some-variant' => [
        //     'reason' => 'Deliberately not offered: …',
        //     'decidedOn' => '2026-09-24',
        // ],
    ];

    /**
     * @return array<string, array{reason: string, decidedOn: string}>
     */
    public static function entries(): array
    {
        /** @var array<string, array{reason: string, decidedOn: string}> $entries */
        $entries = self::ENTRIES;

        return $entries;
    }

    public static function contains(string $provider, string $modelId): bool
    {
        return array_key_exists(self::key($provider, $modelId), self::entries());
    }

    public static function key(string $provider, string $modelId): string
    {
        return strtolower(trim($provider)).':'.strtolower(trim($modelId));
    }
}
