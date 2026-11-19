<?php

declare(strict_types=1);

namespace App\Service\Setup;

use App\Repository\ConfigRepository;
use App\Service\Auth\RegistrationConfig;
use App\Service\Billing\PlanLimitEnforcement;

/**
 * Closed defaults for a new installation, written once when its first
 * administrator is created (setup wizard or BOOTSTRAP_ADMIN_*).
 *
 * - Sign-up is off until an administrator turns it on, so nobody leaves an
 *   open door by accident. The setup wizard's access step then stores the
 *   administrator's own choice.
 * - A Free-plan account gets no usage: every RATELIMITS_NEW quota is 0 and
 *   the plan limits apply even without billing. A stranger who signs up cannot
 *   spend the administrator's provider keys until someone gives that account
 *   a plan.
 *
 * An installation that already had users never reaches this class, and the
 * demo fixtures do not create their administrator through it, so existing
 * installs and the development stack keep their behaviour.
 */
final readonly class FreshInstallDefaults
{
    public const FREE_PLAN_GROUP = 'RATELIMITS_NEW';

    /** Usage quotas of the Free plan. MAX_OUTPUT_TOKENS is not a quota: 0 means "no cap" there. */
    public const FREE_PLAN_QUOTAS = [
        'MESSAGES_TOTAL',
        'IMAGES_TOTAL',
        'VIDEOS_TOTAL',
        'AUDIOS_TOTAL',
        'FILE_ANALYSIS_TOTAL',
        'TRANSCRIPTION_TOTAL',
        'FILE_UPLOADS_TOTAL',
        'STORAGE_MB',
        'COMPUTE_RUNS_TOTAL',
        'COMPUTE_CONCURRENT',
        'COMPUTE_CPU_SECONDS_DAILY',
        'COMPUTE_WORKSPACE_MB',
    ];

    public function __construct(
        private ConfigRepository $configRepository,
    ) {
    }

    public function apply(): void
    {
        $this->configRepository->insertIfAbsent(0, RegistrationConfig::CONFIG_GROUP, RegistrationConfig::KEY_ENABLED, 'false');
        $this->configRepository->insertIfAbsent(0, PlanLimitEnforcement::CONFIG_GROUP, PlanLimitEnforcement::KEY_WITHOUT_BILLING, 'true');

        // The seeder may already have written its numbers on this boot; on a
        // new installation nobody has edited them yet.
        foreach (self::FREE_PLAN_QUOTAS as $setting) {
            $this->configRepository->setValue(0, self::FREE_PLAN_GROUP, $setting, '0');
        }
    }
}
