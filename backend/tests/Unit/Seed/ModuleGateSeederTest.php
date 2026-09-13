<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seed;

use App\Module\Gate\ModuleGateConfig;
use App\Seed\ModuleGateSeeder;
use App\Tests\Unit\Module\Fixture\BuildsAllModules;
use PHPUnit\Framework\TestCase;

/**
 * C5: one `MODULES.GATE_<ID>` row per declared module.
 * FM21 turns gates on for new installs one id at a time; existing rows stay.
 */
final class ModuleGateSeederTest extends TestCase
{
    use BuildsAllModules;

    public function testOneGlobalRowPerModuleWithTikaOn(): void
    {
        $ids = array_keys($this->allModules());
        sort($ids);

        $rows = ModuleGateSeeder::defaultRows($ids);

        $this->assertCount(12, $rows);
        $bySetting = [];
        foreach ($rows as $row) {
            $this->assertSame(0, $row['ownerId']);
            $this->assertSame(ModuleGateConfig::GROUP, $row['group']);
            $bySetting[$row['setting']] = $row['value'];
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
        ], array_keys($bySetting));
        $this->assertSame('1', $bySetting['GATE_TIKA']);
        foreach ($bySetting as $setting => $value) {
            if ('GATE_TIKA' === $setting) {
                continue;
            }
            $this->assertSame('0', $value, $setting);
        }
    }

    public function testRowsFollowTheModuleIdList(): void
    {
        $this->assertSame([], ModuleGateSeeder::defaultRows([]));
        $this->assertSame(
            [['ownerId' => 0, 'group' => 'MODULES', 'setting' => 'GATE_TIKA', 'value' => '1']],
            ModuleGateSeeder::defaultRows(['tika']),
        );
        $this->assertSame(
            [['ownerId' => 0, 'group' => 'MODULES', 'setting' => 'GATE_DOCLING', 'value' => '0']],
            ModuleGateSeeder::defaultRows(['docling']),
        );
    }
}
