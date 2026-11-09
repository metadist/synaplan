<?php

declare(strict_types=1);

namespace App\Service\Chat\Run;

use App\Repository\ConfigRepository;

/**
 * The pump switch. Missing config is off, so an install that has not been
 * seeded still answers in the request.
 */
final class StreamPumpConfig
{
    public const CONFIG_GROUP = 'RUNTIME';
    public const KEY_MODE = 'STREAM_PUMP';
    public const KEY_PERCENT = 'STREAM_PUMP_PERCENT';

    private static ?StreamPumpMode $modeOverride = null;
    private static ?int $percentOverride = null;

    public function __construct(
        private readonly ?ConfigRepository $config = null,
    ) {
    }

    public static function setOverride(?StreamPumpMode $mode, ?int $percent = null): void
    {
        self::$modeOverride = $mode;
        self::$percentOverride = $percent;
    }

    public function mode(): StreamPumpMode
    {
        if (null !== self::$modeOverride) {
            return self::$modeOverride;
        }
        if (null === $this->config) {
            return StreamPumpMode::Off;
        }
        try {
            $raw = $this->config->getValue(0, self::CONFIG_GROUP, self::KEY_MODE);
        } catch (\Throwable) {
            return StreamPumpMode::Off;
        }

        return StreamPumpMode::fromRaw(is_string($raw) ? $raw : null);
    }

    public function percent(): int
    {
        if (null !== self::$percentOverride) {
            return max(0, min(100, self::$percentOverride));
        }
        if (null === $this->config) {
            return 0;
        }
        try {
            $raw = $this->config->getValue(0, self::CONFIG_GROUP, self::KEY_PERCENT);
        } catch (\Throwable) {
            return 0;
        }
        if (!is_string($raw) || !ctype_digit($raw)) {
            return 0;
        }

        return max(0, min(100, (int) $raw));
    }

    /**
     * One turn stays in one mode. The bucket is the run id, so later rounds
     * of the same run do not jump between the pump and the request.
     */
    public function handles(string $channel, string $runId): bool
    {
        $mode = $this->mode();
        if (StreamPumpMode::Off === $mode) {
            return false;
        }
        if (StreamPumpMode::Gateways === $mode && !in_array($channel, ['openai_api', 'messages_api'], true)) {
            return false;
        }
        $percent = $this->percent();
        if ($percent <= 0) {
            return false;
        }
        if ($percent >= 100) {
            return true;
        }

        return (crc32($runId) % 100) < $percent;
    }
}
