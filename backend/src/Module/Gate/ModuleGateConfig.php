<?php

declare(strict_types=1);

namespace App\Module\Gate;

use App\Entity\Config;
use App\Repository\ConfigRepository;
use App\Service\Feature\FeatureFlagEnv;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Reads the per-module gate flags `MODULES.GATE_<ID>` (BCONFIG, ownerId 0).
 *
 * A gate that is ON lets {@see \App\Module\Http\ModuleGateListener} answer a
 * uniform 404 on the module's routes while the module is absent. Every flag is
 * seeded OFF ({@see \App\Seed\ModuleGateSeeder}) and an unknown or malformed
 * value also reads as OFF, so an installation never gates anything it did not
 * opt into. Operators flip a gate under Operate → System configuration →
 * Features, or pin it with `FEATURE_MODULES_GATE_<ID>` ({@see FeatureFlagEnv}).
 *
 * Not `readonly`: the whole group is loaded with one query on first use and
 * memoized for the rest of the request; {@see ResetInterface} clears the memo
 * between requests so a flag flipped in the database is honoured on the next one.
 */
final class ModuleGateConfig implements ResetInterface
{
    public const GROUP = 'MODULES';
    public const SETTING_PREFIX = 'GATE_';

    /** @var array<string, bool>|null setting name → flag, null until first use */
    private ?array $flags = null;

    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly ?FeatureFlagEnv $featureFlagEnv = null,
    ) {
    }

    /** `tika` → `GATE_TIKA`. */
    public static function settingFor(string $moduleId): string
    {
        return self::SETTING_PREFIX.strtoupper($moduleId);
    }

    public function isGated(string $moduleId): bool
    {
        $setting = self::settingFor($moduleId);
        $pinned = $this->featureFlagEnv?->forced(self::GROUP, $setting);
        if (null !== $pinned) {
            return $pinned;
        }

        return $this->flags()[$setting] ?? false;
    }

    public function reset(): void
    {
        $this->flags = null;
    }

    /** @return array<string, bool> */
    private function flags(): array
    {
        if (null !== $this->flags) {
            return $this->flags;
        }

        $flags = [];
        foreach ($this->configRepository->getByGroup(0, self::GROUP) as $row) {
            if (!$row instanceof Config || 0 !== $row->getOwnerId()) {
                continue;
            }
            $flags[$row->getSetting()] = filter_var($row->getValue(), \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? false;
        }

        return $this->flags = $flags;
    }
}
