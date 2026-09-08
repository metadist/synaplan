<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seed;

use App\Plug\PlugConfigService;
use App\Seed\PlugsConfigSeeder;
use PHPUnit\Framework\TestCase;

/**
 * C2: a fresh seed produces exactly the sprint S1 §2.3 table.
 */
final class PlugsConfigSeederTest extends TestCase
{
    public function testDefaultRowsMatchSprintTable(): void
    {
        $bySetting = [];
        foreach (PlugsConfigSeeder::defaultRows() as $row) {
            $this->assertSame(0, $row['ownerId']);
            $this->assertSame(PlugConfigService::CONFIG_GROUP, $row['group']);
            $bySetting[$row['setting']] = $row['value'];
        }

        $this->assertSame([
            PlugConfigService::KEY_CHAIN_TEXT => 'native',
            PlugConfigService::KEY_CHAIN_DOCUMENT => 'structured_office,office_convert,tika,pdf_vision',
            PlugConfigService::KEY_CHAIN_IMAGE => 'vision',
            PlugConfigService::KEY_CHAIN_AUDIO => 'stt_cloud,whisper_local',
            PlugConfigService::KEY_CHAIN_AUDIO_NO_CLOUD => 'whisper_local,stt_cloud',
            PlugConfigService::KEY_CHAIN_VIDEO => 'video_analysis',
            PlugConfigService::KEY_QUALITY_MIN_LENGTH => '10',
            PlugConfigService::KEY_QUALITY_MIN_ENTROPY => sprintf('%.1f', PlugConfigService::DEFAULT_MIN_ENTROPY),
            PlugConfigService::KEY_QUALITY_APPLY_TO => 'pdf',
            PlugConfigService::KEY_WEB_SEARCH_PROVIDER => 'brave',
            PlugConfigService::KEY_WEB_SEARCH_FALLBACK => '',
            PlugConfigService::KEY_RERANK_ENABLED => '0',
            PlugConfigService::KEY_RERANK_CANDIDATES_MULTIPLIER => '4',
            PlugConfigService::KEY_RERANK_LATENCY_BUDGET_MS => '800',
        ], $bySetting);
    }
}
