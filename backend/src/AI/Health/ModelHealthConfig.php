<?php

declare(strict_types=1);

namespace App\AI\Health;

use App\Repository\ConfigRepository;

/**
 * Operator settings for the health monitor, from BCONFIG group MODELHEALTH.
 *
 * Defaults here mirror Version20260819120000. They only apply when a row is
 * missing entirely — the migration is what puts the values in front of installs
 * that already exist, because seeded defaults never reach them.
 *
 * Values are memoized per process: the health check reads them once per model
 * and this must not turn into one query per model.
 */
final class ModelHealthConfig
{
    public const GROUP = 'MODELHEALTH';

    private const DEFAULTS = [
        'ENABLED' => '1',
        'ERROR_RATE_PERCENT' => '50',
        'MIN_SAMPLE_SIZE' => '5',
        'WINDOW_MINUTES' => '30',
        'PROBE_INTERVAL_MINUTES' => '15',
        'ALERT_THROTTLE_MINUTES' => '60',
        'AUTO_DISABLE_ENABLED' => '0',
        'SUPPRESSION_DAYS' => '7',
    ];

    private const MEMO_TTL_SECONDS = 30;

    /** @var array<string, string> */
    private array $memo = [];

    private int $memoAt = 0;

    public function __construct(private readonly ConfigRepository $configRepository)
    {
    }

    public function isEnabled(): bool
    {
        return '1' === $this->get('ENABLED');
    }

    public function isAutoDisableEnabled(): bool
    {
        return '1' === $this->get('AUTO_DISABLE_ENABLED');
    }

    /** Failure share (0..100) at which a model is reported as degraded. */
    public function errorRatePercent(): int
    {
        return max(1, min(100, (int) $this->get('ERROR_RATE_PERCENT')));
    }

    /** Below this many samples the rate is noise and no verdict is formed. */
    public function minSampleSize(): int
    {
        return max(1, (int) $this->get('MIN_SAMPLE_SIZE'));
    }

    public function windowSeconds(): int
    {
        return max(60, (int) $this->get('WINDOW_MINUTES') * 60);
    }

    public function probeIntervalSeconds(): int
    {
        return max(60, (int) $this->get('PROBE_INTERVAL_MINUTES') * 60);
    }

    public function alertThrottleSeconds(): int
    {
        return max(60, (int) $this->get('ALERT_THROTTLE_MINUTES') * 60);
    }

    /** How long the automation stays hands-off after a manual re-enable. */
    public function suppressionSeconds(): int
    {
        return max(0, (int) $this->get('SUPPRESSION_DAYS') * 86400);
    }

    private function get(string $setting): string
    {
        $now = time();
        if ($now - $this->memoAt >= self::MEMO_TTL_SECONDS) {
            $this->memo = [];
            $this->memoAt = $now;
        }

        if (isset($this->memo[$setting])) {
            return $this->memo[$setting];
        }

        $value = null;

        try {
            $value = $this->configRepository->getValue(0, self::GROUP, $setting);
        } catch (\Throwable) {
            // Health monitoring must never take a request down with it — an
            // unavailable BCONFIG (early boot, migration in flight) falls back
            // to the shipped defaults.
            $value = null;
        }

        if (null === $value || '' === trim($value)) {
            $value = self::DEFAULTS[$setting] ?? '';
        }

        return $this->memo[$setting] = $value;
    }
}
