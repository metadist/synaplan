<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

/**
 * The installation secret that signs attach tokens.
 *
 * Boot reads the environment. An empty secret means the web worker tails
 * the run itself: the watchdog is not given a directive it could not check.
 */
final class AttachSecret
{
    public function value(): string
    {
        $value = $_ENV['SYNAPLAN_ATTACH_SECRET'] ?? $_SERVER['SYNAPLAN_ATTACH_SECRET'] ?? getenv('SYNAPLAN_ATTACH_SECRET');

        return is_string($value) ? $value : '';
    }
}
