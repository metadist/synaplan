<?php

declare(strict_types=1);

namespace App\Service\Auth;

/**
 * Dotted-path claim reader shared by OIDC role mapping and directory group sync.
 *
 * Behaviour matches the previous private helpers on {@see \App\Service\OidcUserService}:
 * comma-separated paths, `{client_id}` substitution, escaped dots.
 */
final readonly class OidcClaimResolver
{
    /**
     * @return list<list<string>>
     */
    public function paths(string $spec, string $clientId): array
    {
        $raw = array_map('trim', explode(',', $spec));
        $paths = [];
        foreach ($raw as $path) {
            if ('' === $path) {
                continue;
            }
            $path = str_replace('{client_id}', $clientId, $path);
            $segments = preg_split('/(?<!\\\\)\./', $path);
            if (false === $segments) {
                continue;
            }
            $paths[] = array_map(static fn (string $s): string => str_replace('\\.', '.', $s), $segments);
        }

        return $paths;
    }

    /**
     * Flatten every matching claim path into a unique list of string values.
     *
     * @param array<string, mixed> $claims
     * @param list<list<string>>   $paths
     *
     * @return list<string>
     */
    public function values(array $claims, array $paths): array
    {
        $out = [];
        foreach ($paths as $segments) {
            $value = $this->resolve($claims, $segments);
            if (is_string($value) && '' !== $value) {
                $out[] = $value;
                continue;
            }
            if (!is_array($value)) {
                continue;
            }
            foreach ($value as $item) {
                if (is_string($item) && '' !== $item) {
                    $out[] = $item;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<string, mixed> $claims
     */
    public function issuer(array $claims, string $discoveryUrl = ''): string
    {
        $iss = $claims['iss'] ?? null;
        if (is_string($iss) && '' !== $iss) {
            return $iss;
        }

        $discovery = rtrim($discoveryUrl, '/');
        $fromDiscovery = preg_replace('#/\.well-known/openid-configuration$#', '', $discovery) ?? $discovery;

        return '' !== $fromDiscovery ? $fromDiscovery : 'unknown';
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $segments
     */
    public function resolve(array $data, array $segments): mixed
    {
        $current = $data;
        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }
}
