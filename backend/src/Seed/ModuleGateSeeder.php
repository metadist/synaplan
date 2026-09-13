<?php

declare(strict_types=1);

namespace App\Seed;

use App\Module\Gate\ModuleGateConfig;
use App\Module\ModuleRegistry;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for the per-module gate flags `MODULES.GATE_<ID>` (BCONFIG, ownerId=0).
 *
 * One row per declared feature module. Most gates seed OFF (`0`) so an
 * existing install keeps answering as before; Intermezzo S4 FM21 flips
 * gates ON for new installs one module at a time ({@see DEFAULT_ON}).
 * Insert-if-missing only — operator overrides and existing rows are never
 * touched (no migration on upgrades).
 */
final readonly class ModuleGateSeeder
{
    /** Module ids whose `MODULES.GATE_<ID>` seeds ON for new installs. */
    private const DEFAULT_ON = ['tika', 'docling', 'office_convert', 'searxng', 'piper_tts', 'local_ai', 'higgsfield', 'google_ai'];

    public function __construct(
        private Connection $connection,
        private ModuleRegistry $modules,
    ) {
    }

    public static function defaultValue(string $moduleId): string
    {
        return in_array($moduleId, self::DEFAULT_ON, true) ? '1' : '0';
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
                'value' => self::defaultValue($id),
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
