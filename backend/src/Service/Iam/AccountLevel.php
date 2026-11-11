<?php

declare(strict_types=1);

namespace App\Service\Iam;

/**
 * The one order of account levels. ANONYMOUS is a channel identity and is
 * not part of it. A missing or unknown level never wins.
 */
final class AccountLevel
{
    /** @var array<string, int> */
    public const RANK = [
        'NEW' => 0,
        'PRO' => 1,
        'TEAM' => 2,
        'BUSINESS' => 3,
        'ADMIN' => 4,
    ];

    public static function known(string $level): bool
    {
        return isset(self::RANK[strtoupper($level)]);
    }

    public static function rank(string $level): int
    {
        return self::RANK[strtoupper($level)] ?? -1;
    }

    /**
     * @param list<string> $levels
     */
    public static function highest(array $levels): ?string
    {
        $best = null;
        $rank = -1;
        foreach ($levels as $level) {
            $level = strtoupper($level);
            $next = self::RANK[$level] ?? -1;
            if ($next > $rank) {
                $rank = $next;
                $best = $level;
            }
        }

        return $best;
    }

    public static function sentence(string $level): string
    {
        return ucfirst(strtolower($level));
    }
}
