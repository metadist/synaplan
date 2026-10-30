<?php

declare(strict_types=1);

namespace App\Service\Egress;

use App\Service\Security\SsrfGuard;

/**
 * Adapts {@see SsrfGuard} so the egress policy does not depend on DNS
 * details of its own.
 */
final readonly class SsrfEgressGuard implements EgressTypedUrlGuard
{
    public function __construct(
        private SsrfGuard $ssrfGuard,
    ) {
    }

    public function allows(string $url): bool
    {
        return !$this->ssrfGuard->isBlockedUrl($url);
    }

    public function pins(string $host): array
    {
        return $this->ssrfGuard->pinnedIps($host);
    }
}
