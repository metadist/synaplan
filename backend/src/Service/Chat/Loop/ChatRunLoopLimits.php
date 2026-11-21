<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Repository\ConfigRepository;

/**
 * The admin keys of the run guards (03 §1.4 and the reserve of §1.3), with
 * the defaults the owner approved. A missing or broken row uses the default.
 */
final readonly class ChatRunLoopLimits
{
    public const STALL_REPEAT = 3;
    public const STALL_NO_NEW = 6;
    public const STALL_ERRORS = 5;
    public const WRAPUP_RESERVE_PERCENT = 10;

    public function __construct(private ?ConfigRepository $config = null)
    {
    }

    /** Runs of the same tool with the same normalised input. */
    public function repeat(): int
    {
        return $this->read('STALL_REPEAT', self::STALL_REPEAT, 2, 100);
    }

    /** Steps in a row whose tool results brought nothing new. */
    public function noNew(): int
    {
        return $this->read('STALL_NO_NEW', self::STALL_NO_NEW, 2, 100);
    }

    /** Tool errors in a row. */
    public function errors(): int
    {
        return $this->read('STALL_ERRORS', self::STALL_ERRORS, 2, 100);
    }

    /** The share of a run's limit held back for the wrap-up call. */
    public function reservePercent(): int
    {
        return $this->read('WRAPUP_RESERVE_PERCENT', self::WRAPUP_RESERVE_PERCENT, 1, 50);
    }

    private function read(string $setting, int $default, int $min, int $max): int
    {
        $stored = $this->config?->getValue(0, ChatLoopConfig::GROUP, $setting);
        if (null === $stored || !ctype_digit(trim($stored))) {
            return $default;
        }
        $value = (int) trim($stored);

        return $value >= $min && $value <= $max ? $value : $default;
    }
}
