<?php

declare(strict_types=1);

namespace App\Seed;

use App\Plug\PlugConfigService;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for the PLUGS group (BCONFIG, ownerId=0).
 *
 * Insert-if-missing only — operator overrides are never touched. Values
 * reproduce today's FileProcessor order and Brave-only web search so a
 * fresh install is byte-identical with the pre-plug behaviour.
 */
final readonly class PlugsConfigSeeder
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function seed(): SeedResult
    {
        return BConfigSeeder::insertIfMissing($this->connection, 'plugs_config', self::defaultRows());
    }

    /**
     * Sprint S1 §2.3 table. Tests assert this list; seed() inserts it as-is.
     *
     * @return list<array{ownerId: int, group: string, setting: string, value: string}>
     */
    public static function defaultRows(): array
    {
        $group = PlugConfigService::CONFIG_GROUP;

        return [
            ['ownerId' => 0, 'group' => $group, 'setting' => PlugConfigService::KEY_CHAIN_TEXT, 'value' => PlugConfigService::DEFAULT_CHAIN_TEXT],
            ['ownerId' => 0, 'group' => $group, 'setting' => PlugConfigService::KEY_CHAIN_DOCUMENT, 'value' => PlugConfigService::DEFAULT_CHAIN_DOCUMENT],
            ['ownerId' => 0, 'group' => $group, 'setting' => PlugConfigService::KEY_CHAIN_IMAGE, 'value' => PlugConfigService::DEFAULT_CHAIN_IMAGE],
            ['ownerId' => 0, 'group' => $group, 'setting' => PlugConfigService::KEY_CHAIN_AUDIO, 'value' => PlugConfigService::DEFAULT_CHAIN_AUDIO],
            ['ownerId' => 0, 'group' => $group, 'setting' => PlugConfigService::KEY_CHAIN_AUDIO_NO_CLOUD, 'value' => PlugConfigService::DEFAULT_CHAIN_AUDIO_NO_CLOUD],
            ['ownerId' => 0, 'group' => $group, 'setting' => PlugConfigService::KEY_CHAIN_VIDEO, 'value' => PlugConfigService::DEFAULT_CHAIN_VIDEO],
            ['ownerId' => 0, 'group' => $group, 'setting' => PlugConfigService::KEY_QUALITY_MIN_LENGTH, 'value' => (string) PlugConfigService::DEFAULT_MIN_LENGTH],
            ['ownerId' => 0, 'group' => $group, 'setting' => PlugConfigService::KEY_QUALITY_MIN_ENTROPY, 'value' => sprintf('%.1f', PlugConfigService::DEFAULT_MIN_ENTROPY)],
            ['ownerId' => 0, 'group' => $group, 'setting' => PlugConfigService::KEY_QUALITY_APPLY_TO, 'value' => PlugConfigService::DEFAULT_QUALITY_APPLY_TO],
            ['ownerId' => 0, 'group' => $group, 'setting' => PlugConfigService::KEY_WEB_SEARCH_PROVIDER, 'value' => PlugConfigService::DEFAULT_WEB_SEARCH_PROVIDER],
            ['ownerId' => 0, 'group' => $group, 'setting' => PlugConfigService::KEY_WEB_SEARCH_FALLBACK, 'value' => ''],
            ['ownerId' => 0, 'group' => $group, 'setting' => PlugConfigService::KEY_RERANK_ENABLED, 'value' => '0'],
            ['ownerId' => 0, 'group' => $group, 'setting' => PlugConfigService::KEY_RERANK_CANDIDATES_MULTIPLIER, 'value' => (string) PlugConfigService::DEFAULT_RERANK_MULTIPLIER],
            ['ownerId' => 0, 'group' => $group, 'setting' => PlugConfigService::KEY_RERANK_LATENCY_BUDGET_MS, 'value' => (string) PlugConfigService::DEFAULT_RERANK_LATENCY_MS],
        ];
    }
}
