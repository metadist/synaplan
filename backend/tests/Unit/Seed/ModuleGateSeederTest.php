<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seed;

use App\Module\Gate\ModuleGateConfig;
use App\Seed\ModuleGateSeeder;
use App\Tests\Unit\Module\Fixture\BuildsAllModules;
use PHPUnit\Framework\TestCase;

/**
 * C5: one `MODULES.GATE_<ID>` row per declared module, every one of them OFF.
 */
final class ModuleGateSeederTest extends TestCase
{
    use BuildsAllModules;

    public function testOneGlobalOffRowPerModule(): void
    {
        $ids = array_keys($this->allModules());
        sort($ids);

        $rows = ModuleGateSeeder::defaultRows($ids);

        $this->assertCount(12, $rows);
        $settings = [];
        foreach ($rows as $row) {
            $this->assertSame(0, $row['ownerId']);
            $this->assertSame(ModuleGateConfig::GROUP, $row['group']);
            $this->assertSame('0', $row['value']);
            $settings[] = $row['setting'];
        }

        $this->assertSame([
            'GATE_DOCLING',
            'GATE_GOOGLE_AI',
            'GATE_HIGGSFIELD',
            'GATE_LOCAL_AI',
            'GATE_MOBILE_IAP',
            'GATE_OFFICE_CONVERT',
            'GATE_PIPER_TTS',
            'GATE_SEARXNG',
            'GATE_STRIPE_BILLING',
            'GATE_THEHIVE',
            'GATE_TIKA',
            'GATE_WHATSAPP',
        ], $settings);
    }

    public function testRowsFollowTheModuleIdList(): void
    {
        $this->assertSame([], ModuleGateSeeder::defaultRows([]));
        $this->assertSame(
            [['ownerId' => 0, 'group' => 'MODULES', 'setting' => 'GATE_TIKA', 'value' => '0']],
            ModuleGateSeeder::defaultRows(['tika']),
        );
    }
}
