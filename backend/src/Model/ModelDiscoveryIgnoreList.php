<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Reviewed decisions to skip upstream model ids in new-model discovery.
 *
 * Exact {@see ENTRIES} are `provider:id` keys (inventory provider + listing id,
 * lowercased). {@see CLASS_RULES} skip whole classes we never sell (prefix or
 * contains), scoped per provider. Neither filters the catalog — they only
 * silence discovery. Delete an exact entry when the id disappears upstream or
 * lands in BMODELS; remove a class rule when we start selling that class
 * (the catalog guard test will fail until you do).
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
     * Provider-scoped class skips (never sell). Embeddings, TTS, whisper,
     * image and video must stay reportable — do not add rules for those.
     *
     * @var list<array{provider: string, match: 'prefix'|'contains', value: string, reason: string, decidedOn: string}>
     */
    public const CLASS_RULES = [
        [
            'provider' => 'openai',
            'match' => 'prefix',
            'value' => 'ft:',
            'reason' => 'Account-specific fine-tunes, not a shared catalog offering',
            'decidedOn' => '2026-09-25',
        ],
        [
            'provider' => 'openai',
            'match' => 'contains',
            'value' => 'moderation',
            'reason' => 'Moderation endpoints, not offered',
            'decidedOn' => '2026-09-25',
        ],
        [
            'provider' => 'openai',
            'match' => 'contains',
            'value' => 'realtime',
            'reason' => 'Realtime/voice sessions, not offered',
            'decidedOn' => '2026-09-25',
        ],
        [
            'provider' => 'mistral',
            'match' => 'contains',
            'value' => 'moderation',
            'reason' => 'Moderation endpoints, not offered',
            'decidedOn' => '2026-09-25',
        ],
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

    /**
     * @return list<array{provider: string, match: 'prefix'|'contains', value: string, reason: string, decidedOn: string}>
     */
    public static function classRules(): array
    {
        return self::CLASS_RULES;
    }

    public static function contains(string $provider, string $modelId): bool
    {
        return array_key_exists(self::key($provider, $modelId), self::entries())
            || null !== self::matchingClassRule($provider, $modelId);
    }

    /**
     * @return array{provider: string, match: 'prefix'|'contains', value: string, reason: string, decidedOn: string}|null
     */
    public static function matchingClassRule(string $provider, string $modelId): ?array
    {
        $providerKey = strtolower(trim($provider));
        $id = strtolower(trim($modelId));

        foreach (self::CLASS_RULES as $rule) {
            if ($rule['provider'] !== $providerKey) {
                continue;
            }

            $hits = match ($rule['match']) {
                'prefix' => str_starts_with($id, $rule['value']),
                'contains' => str_contains($id, $rule['value']),
            };

            if ($hits) {
                return $rule;
            }
        }

        return null;
    }

    public static function key(string $provider, string $modelId): string
    {
        return strtolower(trim($provider)).':'.strtolower(trim($modelId));
    }
}
