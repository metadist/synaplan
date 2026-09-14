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
            $name = strtolower(trim($host));
            $name = preg_replace('#^https?://#', '', $name) ?? $name;
            $name = explode('/', $name, 2)[0];
            $name = explode(':', $name, 2)[0];
            $name = trim($name, '.');
            if ('' === $name || isset($clean[$name])) {
                continue;
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
}
