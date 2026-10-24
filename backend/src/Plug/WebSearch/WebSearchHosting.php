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
    /** @var array<string, bool> provider name => self-hosted */
    public const PROVIDERS = [
        'searxng' => true,
        'brave' => false,
        'tavily' => false,
        'exa' => false,
        'firecrawl' => false,
        'perplexity' => false,
    ];

    public static function selfHosted(string $provider): bool
    {
        return self::PROVIDERS[strtolower($provider)] ?? false;
    }

    private function __construct()
    {
    }
}
