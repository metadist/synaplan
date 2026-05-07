<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ConfigRepository;

/**
 * Phase 4 rollout switch for the performance overhaul (Phases 1-3).
 *
 * The overhaul changes user-visible behaviour in a few non-trivial ways:
 *   - background memory extraction (Phase 2) instead of inline
 *   - classifier fast-path (Phase 1c) skipping the AI sorter
 *   - Gemini `thinkingConfig` (Phase 1e) reducing reasoning by default
 *
 * This service exposes a single `isEnabled()` check so ops can disable the
 * whole bundle in one place if they spot a regression in production. Reads
 * BCONFIG group `PERF`, key `V2_PIPELINE_ENABLED`. Per-user overrides take
 * precedence over the global value; the default is **on** (the new code
 * path) because the old inline memory extraction was already removed —
 * disabling the flag now means "skip memory extraction entirely" rather
 * than "go back to inline".
 *
 * To kill switch globally:
 *   INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
 *     VALUES (0, 'PERF', 'V2_PIPELINE_ENABLED', '0');
 *
 * To opt one user out (e.g. for A/B comparison):
 *   INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
 *     VALUES (<userId>, 'PERF', 'V2_PIPELINE_ENABLED', '0');
 */
final readonly class PerfPipelineFlag
{
    private const GROUP = 'PERF';
    private const SETTING = 'V2_PIPELINE_ENABLED';

    public function __construct(private ConfigRepository $configRepository)
    {
    }

    /**
     * Whether the v2 pipeline is enabled for this user (default: true).
     *
     * Pass `null` for system / cron callers — those always check the global row.
     */
    public function isEnabled(?int $userId = null): bool
    {
        if (null !== $userId && $userId > 0) {
            $perUser = $this->configRepository->getValue($userId, self::GROUP, self::SETTING);
            if (null !== $perUser) {
                return $this->parse($perUser);
            }
        }

        $global = $this->configRepository->getValue(0, self::GROUP, self::SETTING);
        if (null === $global) {
            return true;
        }

        return $this->parse($global);
    }

    private function parse(string $value): bool
    {
        return filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? true;
    }
}
