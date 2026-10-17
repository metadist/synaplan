<?php

declare(strict_types=1);

namespace App\Service\Admin\SystemConfig;

use App\Repository\ConfigRepository;
use App\Service\Compute\ComputeClient;
use App\Service\Compute\ComputeConfig;

/**
 * Refuses a file-work setting the connected compute sidecar cannot honour:
 * a limit above its caps, a feature it does not offer, file work below the
 * required isolation tier, or an unknown tier. Switching something off is
 * always allowed.
 */
final readonly class ComputeSettingGuard
{
    public function __construct(
        private ConfigRepository $configRepository,
        private ?ComputeClient $computeClient = null,
    ) {
    }

    /**
     * @return array{success: false, requiresRestart: false, message: string}|null null when the value may be stored
     */
    public function refusal(string $key, string $value): ?array
    {
        return $this->aboveSidecarCap($key, $value)
            ?? $this->featureSidecarLacks($key, $value)
            ?? $this->belowRequiredTier($key, $value)
            ?? $this->invalidTier($key, $value);
    }

    /**
     * @return array{success: false, requiresRestart: false, message: string}|null
     */
    private function aboveSidecarCap(string $key, string $value): ?array
    {
        $map = [
            'COMPUTE_DEFAULT_TIMEOUT_SEC' => ['timeoutSec', 'seconds'],
            'COMPUTE_MAX_TIMEOUT_SEC' => ['timeoutSec', 'seconds'],
            'COMPUTE_DEFAULT_MEMORY_MB' => ['memoryMb', 'MB of memory'],
            'COMPUTE_DEFAULT_CPU' => ['cpu', 'CPU'],
            'COMPUTE_DEFAULT_PIDS' => ['pids', 'processes'],
            'COMPUTE_DEFAULT_OUTPUT_MB' => ['outputMb', 'MB of result files'],
        ];
        if (!isset($map[$key]) || null === $this->computeClient || !is_numeric($value)) {
            return null;
        }
        try {
            $caps = $this->computeClient->health()->caps;
        } catch (\Throwable) {
            return null;
        }
        [$capKey, $unit] = $map[$key];
        $cap = $caps[$capKey];
        if ((float) $value <= (float) $cap) {
            return null;
        }

        return [
            'success' => false,
            'requiresRestart' => false,
            'message' => sprintf(
                'This installation\'s file-work limit is %s %s. Enter a value at or below that.',
                $cap,
                $unit,
            ),
        ];
    }

    /**
     * A compute child flag may only be switched on when the connected sidecar
     * reports the feature in `GET /v1/health`. Otherwise the UI would show a
     * Workspace tab or promise website access that every run then refuses
     * (U11: a flag must never be a teaser). Switching off is always allowed.
     *
     * @return array{success: false, requiresRestart: false, message: string}|null
     */
    private function featureSidecarLacks(string $key, string $value): ?array
    {
        $map = [
            'COMPUTE_WORKSPACES_ENABLED' => ['workspaces', 'Persistent file-work folders are'],
            'COMPUTE_EGRESS_ENABLED' => ['egress', 'Website access for file work is'],
        ];
        if (!isset($map[$key]) || null === $this->computeClient) {
            return null;
        }
        if (!in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true)) {
            return null;
        }
        try {
            $features = $this->computeClient->health()->features;
        } catch (\Throwable) {
            return [
                'success' => false,
                'requiresRestart' => false,
                'message' => 'File work is not reachable right now. The switch stays off.',
            ];
        }
        [$featureKey, $subject] = $map[$key];
        if ($features[$featureKey]) {
            return null;
        }

        return [
            'success' => false,
            'requiresRestart' => false,
            'message' => $subject.' not offered by this installation\'s compute sidecar yet. The switch stays off.',
        ];
    }

    /**
     * COMPUTE.ENABLED may only be switched on when the connected sidecar
     * reports at least the required isolation tier (CS31). The runtime gate
     * in ComputeConfig::isEnabled() enforces the same rule continuously, so
     * raising REQUIRE_TIER later disables compute immediately without a
     * refusal. Switching off is always allowed.
     *
     * @return array{success: false, requiresRestart: false, message: string}|null
     */
    private function belowRequiredTier(string $key, string $value): ?array
    {
        if ('COMPUTE_ENABLED' !== $key || null === $this->computeClient) {
            return null;
        }
        if (!in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true)) {
            return null;
        }
        try {
            $tier = $this->computeClient->health()->tier;
        } catch (\Throwable) {
            return [
                'success' => false,
                'requiresRestart' => false,
                'message' => 'File work is not reachable right now. The switch stays off.',
            ];
        }
        $required = ComputeConfig::normalizeTier(
            $this->configRepository->getValue(0, ComputeConfig::CONFIG_GROUP, ComputeConfig::KEY_REQUIRE_TIER)
        );
        if (ComputeConfig::tierAtLeast($tier, $required)) {
            return null;
        }

        return [
            'success' => false,
            'requiresRestart' => false,
            'message' => sprintf(
                'the compute service reports %s; this instance requires %s',
                ComputeConfig::tierDisplayName($tier),
                ComputeConfig::tierDisplayName($required),
            ),
        ];
    }

    /**
     * The tier requirement is only meaningful as a known tier. Reject anything
     * else at write time: reads fail closed to the strictest tier, so a bad
     * row would silently pin this instance to microVM.
     *
     * @return array{success: false, requiresRestart: false, message: string}|null
     */
    private function invalidTier(string $key, string $value): ?array
    {
        if ('COMPUTE_REQUIRE_TIER' !== $key) {
            return null;
        }
        if (isset(ComputeConfig::TIER_ORDER[strtolower(trim($value))])) {
            return null;
        }

        return [
            'success' => false,
            'requiresRestart' => false,
            'message' => 'Isolation tier must be one of: docker, gvisor, microvm.',
        ];
    }
}
