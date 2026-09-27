<?php

declare(strict_types=1);

namespace App\Service\Desktop;

use App\Entity\DesktopDevice;

/**
 * Whether a paired computer is actually reachable.
 *
 * A still-valid key is not the same as a running app. The desktop client
 * checks in at {@see DesktopJobContract::NEXT_CALL_IDLE_SECONDS} when it has
 * no work, so a check-in older than that means it is not connected. A key
 * that has never checked in is not connected either.
 */
final class DesktopDevicePresence
{
    public const ONLINE = 'online';
    public const AWAY = 'away';
    public const NEVER = 'never';
    public const REVOKED = 'revoked';

    /** @var list<string> */
    public const VALUES = [
        self::ONLINE,
        self::AWAY,
        self::NEVER,
        self::REVOKED,
    ];

    /**
     * Idle check-in bound, in seconds. Equal to the poll hint the server
     * sends the app, so the page and the app agree on what "recently" means.
     */
    public const CHECK_IN_SECONDS = DesktopJobContract::NEXT_CALL_IDLE_SECONDS;

    private function __construct()
    {
    }

    public static function resolve(string $status, int $lastSeen, ?int $now = null): string
    {
        if (DesktopDevice::STATUS_ACTIVE !== $status) {
            return self::REVOKED;
        }

        if ($lastSeen <= 0) {
            return self::NEVER;
        }

        $now ??= time();
        if (($now - $lastSeen) <= self::CHECK_IN_SECONDS) {
            return self::ONLINE;
        }

        return self::AWAY;
    }
}
