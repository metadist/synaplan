<?php

declare(strict_types=1);

namespace App\Seed;

use App\AI\Pipeline\UsageHookConfig;
use App\AI\Pipeline\UsageHookMode;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Idempotent seeder for the global AI call pipeline settings (BCONFIG, ownerId=0).
 *
 * AU11 Part 1 seeds `AI.USAGE_HOOK = legacy`. Insert-if-missing only — operator
 * overrides (`shadow` or `on`) are never overwritten.
 */
#[AsTaggedItem(priority: 65)]
final readonly class AiPipelineConfigSeeder implements SeederInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function label(): string
    {
        return 'ai-pipeline';
    }

    public function description(): string
    {
        return 'BCONFIG, group=AI, setting=USAGE_HOOK — default legacy';
    }

    public function seed(): SeedResult
    {
        $rows = [
            [
                'ownerId' => 0,
                'group' => UsageHookConfig::CONFIG_GROUP,
                'setting' => UsageHookConfig::KEY_USAGE_HOOK,
                'value' => UsageHookMode::Legacy->value,
            ],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'ai_pipeline_config', $rows);
    }
}
