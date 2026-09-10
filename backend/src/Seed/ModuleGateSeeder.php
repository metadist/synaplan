<?php

declare(strict_types=1);

namespace App\Seed;

use App\Module\Gate\ModuleGateConfig;
use App\Module\ModuleRegistry;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for the per-module gate flags `MODULES.GATE_<ID>` (BCONFIG, ownerId=0).
 *
 * One row per declared feature module, seeded OFF (`0`): an existing install
 * keeps answering exactly as before until an operator turns a gate on.
 * Insert-if-missing only — operator overrides are never touched.
 */
final readonly class ModuleGateSeeder
{
    public function __construct(
        private Connection $connection,
        private ModuleRegistry $modules,
    ) {
    }

    /**
     * @param list<string> $moduleIds
     *
     * @return list<array{ownerId: int, group: string, setting: string, value: string}>
     */
    public static function defaultRows(array $moduleIds): array
    {
        $rows = [];
        foreach ($moduleIds as $id) {
            $rows[] = [
                'ownerId' => 0,
                'group' => ModuleGateConfig::GROUP,
                'setting' => ModuleGateConfig::settingFor($id),
                'value' => '0',
            ];
        }

        return $rows;
    }

    public function seed(): SeedResult
    {
        return BConfigSeeder::insertIfMissing(
            $this->connection,
            'module_gates',
            self::defaultRows($this->modules->ids()),
        );
    }
}
