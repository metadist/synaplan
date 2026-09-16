<?php

declare(strict_types=1);

namespace App\Plug\WebSearch;

use App\Plug\PlugHealth;

/**
 * Turns a live ping into {@see PlugHealth}. Config-only checks stay on health().
 */
final class WebSearchProbe
{
    public static function run(bool $configured, string $notConfiguredReason, callable $ping): PlugHealth
    {
        if (!$configured) {
            return PlugHealth::unavailable($notConfiguredReason);
        }

        try {
            $ping();
        } catch (\Throwable $e) {
            return PlugHealth::unavailable($e->getMessage());
        }

        return PlugHealth::available();
    }
}
