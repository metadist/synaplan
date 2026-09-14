<?php

declare(strict_types=1);

namespace App\Service\Compute;

use App\Service\Security\SsrfGuard;

/**
 * Pins allowed hosts to public IPs through the same SSRF guard as every outbound fetch.
 */
final readonly class ComputeEgressResolver
{
    public const DEFAULT_PORT = 443;

    /** RFC 1123 host name: dot-separated labels of letters, digits and inner hyphens. */
    private const HOSTNAME = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/';
    private const MAX_HOSTNAME_LENGTH = 253;

    public function __construct(
        private ComputeConfig $config,
        private SsrfGuard $ssrf,
    ) {
    }

    /**
     * @param list<mixed> $hosts
     *
     * @return array{allow: list<array{host: string, port: int, ips: list<string>}>}
     */
    public function resolve(array $hosts, ?int $userId = null): array
    {
        if (!$this->config->egressEnabled($userId)) {
            return ['allow' => []];
        }

        $clean = [];
        foreach ($hosts as $host) {
            if (!is_string($host)) {
                continue;
            }
            $name = self::hostName($host);
            if ('' === $name || isset($clean[$name])) {
                continue;
            }
            if (\strlen($name) > self::MAX_HOSTNAME_LENGTH || 1 !== preg_match(self::HOSTNAME, $name)) {
                throw new ComputeRefusedException('egress_not_allowed', 'This file-work run cannot reach that website. Nothing new was saved.');
            }
            $clean[$name] = $name;
        }
        $names = array_values($clean);
        $max = $this->config->egressMaxHosts();
        if (\count($names) > $max) {
            throw new ComputeRefusedException('egress_not_allowed', 'This file-work run listed too many websites. Nothing new was saved.');
        }

        $allow = [];
        foreach ($names as $name) {
            if ($this->ssrf->isBlockedHost($name)) {
                throw new ComputeRefusedException('egress_not_allowed', 'This file-work run cannot reach that website. Nothing new was saved.');
            }
            $ips = $this->ssrf->pinnedIps($name);
            if ([] === $ips) {
                throw new ComputeRefusedException('egress_not_allowed', 'This file-work run cannot reach that website. Nothing new was saved.');
            }
            $allow[] = [
                'host' => $name,
                'port' => self::DEFAULT_PORT,
                'ips' => $ips,
            ];
        }

        return ['allow' => $allow];
    }

    /**
     * Reduces whatever the planner wrote ("https://user@Api.Example.com:8443/x")
     * to the bare lower-case host. Anything that is not a host name after this
     * step is refused by the caller, never guessed.
     */
    private static function hostName(string $raw): string
    {
        $name = strtolower(trim($raw));
        $name = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $name) ?? $name;
        $name = explode('/', $name, 2)[0];
        $name = explode('?', $name, 2)[0];
        $name = explode('#', $name, 2)[0];
        if (str_contains($name, '@')) {
            $name = substr($name, strrpos($name, '@') + 1);
        }
        $name = explode(':', $name, 2)[0];

        return trim($name, '.');
    }
}
