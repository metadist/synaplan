<?php

declare(strict_types=1);

namespace App\Service\Iam;

/**
 * Why an account has a level. One row per source. A channel only replaces
 * or removes its own row, then the level is recomputed.
 */
final class LevelGrantSource
{
    public const STRIPE = 'stripe';
    public const IAP = 'iap';
    public const CODE = 'code';
    public const ADMIN = 'admin';
    public const DIRECTORY = 'directory';
    public const BOOTSTRAP = 'bootstrap';

    /** @var list<string> */
    public const ALL = [
        self::STRIPE,
        self::IAP,
        self::CODE,
        self::ADMIN,
        self::DIRECTORY,
        self::BOOTSTRAP,
    ];

    /**
     * When two grants share the winning level, name the one the person did
     * not just type. Billing and the directory explain a level that stayed
     * put; the admin grant is the one they are editing.
     *
     * @var array<string, int>
     */
    private const DISPLAY = [
        self::STRIPE => 0,
        self::IAP => 1,
        self::CODE => 2,
        self::DIRECTORY => 3,
        self::BOOTSTRAP => 4,
        self::ADMIN => 5,
    ];

    public static function known(string $source): bool
    {
        return isset(self::DISPLAY[$source]);
    }

    public static function displayRank(string $source): int
    {
        return self::DISPLAY[$source] ?? 99;
    }
}
