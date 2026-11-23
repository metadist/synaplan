<?php

declare(strict_types=1);

namespace App\Service\Security;

/**
 * Asks the system resolver. A failed lookup is an empty list.
 */
final readonly class DnsHostResolver implements HostResolver
{
    public function addresses(string $host): array
    {
        $ips = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }

        $aaaa = @dns_get_record($host, \DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (is_string($record['ipv6'] ?? null) && '' !== $record['ipv6']) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }
}
