<?php

declare(strict_types=1);

namespace App\Plug;

/**
 * The capability ports a plugin may contribute an adapter to, and the DI tag
 * each port's registry consumes. One list so the manifest parser (valid ports)
 * and the boot-time declaration check (port ⇄ tag) never drift.
 */
final class PlugPorts
{
    /** Manifest `provides.plugs[].port` value => the tag its registry iterates. */
    public const TAG_BY_PORT = [
        'extraction' => 'app.plug.extractor',
        'web_search' => 'app.plug.web_search',
        'rerank' => 'app.plug.rerank',
    ];

    public static function isValidPort(string $port): bool
    {
        return isset(self::TAG_BY_PORT[$port]);
    }

    public static function tagForPort(string $port): ?string
    {
        return self::TAG_BY_PORT[$port] ?? null;
    }

    /**
     * @return array<string, string> DI tag => manifest port name
     */
    public static function portByTag(): array
    {
        return array_flip(self::TAG_BY_PORT);
    }
}
