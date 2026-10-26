<?php

declare(strict_types=1);

namespace App\Plug\WebSearch;

/**
 * Where a web-search provider runs. Only SearXNG stays on the installation.
 * The others are US-hosted, so a local or EU world has to say so or refuse
 * them when strict mode is on.
 */
final class WebSearchHosting
{
    /**
     * Provider name => hosting country (ISO 3166-1 alpha-2), or null when the
     * provider runs on the operator's own server.
     *
     * @var array<string, string|null>
     */
    public const PROVIDERS = [
        'searxng' => null,
        'brave' => 'US',
        'tavily' => 'US',
        'exa' => 'US',
        'firecrawl' => 'US',
        'perplexity' => 'US',
    ];

    public static function selfHosted(string $provider): bool
    {
        $key = strtolower($provider);

        return array_key_exists($key, self::PROVIDERS) && null === self::PROVIDERS[$key];
    }

    /**
     * Hosting country of a third-party provider. Null for a self-hosted or an
     * unknown provider; an unknown one is never treated as self-hosted.
     */
    public static function country(string $provider): ?string
    {
        return self::PROVIDERS[strtolower($provider)] ?? null;
    }

    private function __construct()
    {
    }
}
