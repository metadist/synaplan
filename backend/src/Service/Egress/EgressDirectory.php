<?php

declare(strict_types=1);

namespace App\Service\Egress;

/**
 * Writes the allowlist and one-request grants the CONNECT gateway reads.
 * The grant id is the bearer token. Callers must not log it.
 */
final class EgressDirectory
{
    public const GRANT_TTL_SECONDS = 60;
    public const ALLOW_TTL_SECONDS = 86400;

    public function __construct(
        private readonly EgressCache $cache,
    ) {
    }

    /**
     * @param list<string> $rules
     */
    public function publish(array $rules): void
    {
        $this->cache->put('egress:allow', implode("\n", $rules), self::ALLOW_TTL_SECONDS);
    }

    public function issue(string $host, int $port, string $ip): ?string
    {
        $id = bin2hex(random_bytes(16));
        $body = json_encode([
            'host' => $host,
            'port' => $port,
            'ips' => [$ip],
        ], \JSON_THROW_ON_ERROR);
        if (!$this->cache->put('egress:grant:'.$id, $body, self::GRANT_TTL_SECONDS)) {
            return null;
        }

        return $id;
    }
}
