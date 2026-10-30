<?php

declare(strict_types=1);

namespace App\Service\Egress;

/**
 * `EGRESS.MODE`. `off` does not look at the allowlist. `report` lets the
 * request out and records what `enforce` would have refused. `enforce`
 * refuses before a socket opens.
 */
enum EgressMode: string
{
    case Off = 'off';
    case Report = 'report';
    case Enforce = 'enforce';

    public static function fromRaw(?string $raw): self
    {
        return self::tryFrom(strtolower(trim((string) $raw))) ?? self::Off;
    }
}
