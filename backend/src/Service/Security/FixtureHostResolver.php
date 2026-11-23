<?php

declare(strict_types=1);

namespace App\Service\Security;

/**
 * Resolver for the test environment only. Tests name hosts that exist
 * nowhere (`files.example.org`, `cloud.example`), and {@see SsrfGuard}
 * refuses a name without an address. Here such a name answers with one
 * public address, so a test reaches its mocked HTTP client. A name under
 * `.invalid` stays without an answer, and a name the system resolver knows
 * keeps its real answer.
 */
final readonly class FixtureHostResolver implements HostResolver
{
    public const PUBLIC_ADDRESS = '93.184.216.34';

    private const NEVER_RESOLVES = '.invalid';

    public function __construct(
        private HostResolver $dns = new DnsHostResolver(),
    ) {
    }

    public function addresses(string $host): array
    {
        if (str_ends_with($host, self::NEVER_RESOLVES)) {
            return [];
        }
        $answers = $this->dns->addresses($host);

        return [] === $answers ? [self::PUBLIC_ADDRESS] : $answers;
    }
}
