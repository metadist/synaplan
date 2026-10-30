<?php

declare(strict_types=1);

namespace App\Service\Egress;

/**
 * The per-request check for a URL a person typed. The shared allowlist does
 * not learn that host.
 */
interface EgressTypedUrlGuard
{
    public function allows(string $url): bool;

    /**
     * Public addresses the guard accepted for this host, first answer first.
     *
     * @return list<string>
     */
    public function pins(string $host): array;
}
