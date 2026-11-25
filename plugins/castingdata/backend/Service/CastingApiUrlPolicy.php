<?php

declare(strict_types=1);

namespace Plugin\CastingData\Service;

use App\Service\Security\SsrfGuard;

/**
 * Decides whether an address the account owner typed may be saved as the
 * casting API base URL.
 *
 * {@see CastingApiClient} checks every address again when it sends a request,
 * so this is not the security boundary. It applies the same rules at save
 * time, so that an address the client would refuse is not stored as if it
 * worked.
 */
final readonly class CastingApiUrlPolicy
{
    private const ALLOWED_SCHEMES = ['http', 'https'];

    public function __construct(
        private SsrfGuard $ssrfGuard,
    ) {
    }

    /**
     * Why the address cannot be used, or null when it can.
     */
    public function problem(string $url): ?string
    {
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            return 'Invalid API URL format';
        }

        if (!in_array(strtolower($parts['scheme']), self::ALLOWED_SCHEMES, true)) {
            return 'API URL must use HTTP or HTTPS';
        }

        // The client appends its own paths to this address. A query or a
        // fragment would swallow them, and credentials belong in the API key.
        if (isset($parts['query']) || isset($parts['fragment']) || str_contains($url, '?') || str_contains($url, '#')) {
            return 'API URL must be a base address without a query string or fragment';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'API URL must not contain a user name or password';
        }

        if ($this->ssrfGuard->isBlockedUrl($url)) {
            return 'API URL must point to a public host. Local, internal and unresolvable addresses are not allowed';
        }

        return null;
    }
}
