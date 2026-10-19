<?php

declare(strict_types=1);

namespace App\Service\FreeApi;

/**
 * The non-personal facts an install sends when it asks for a Demo key
 * (plan §5.8): where it runs (laptop, private network, public server) and a
 * hash of its host name — never the host name itself.
 */
final readonly class FreeApiInstallFacts
{
    public function __construct(
        private string $appUrl,
    ) {
    }

    /** `localhost`, `private` or `public`. */
    public function hostKind(): string
    {
        $host = $this->host();
        if ('' === $host || 'localhost' === $host || str_ends_with($host, '.localhost')) {
            return 'localhost';
        }

        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            if (str_starts_with($host, '127.') || '::1' === $host) {
                return 'localhost';
            }

            return false === filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE) ? 'private' : 'public';
        }

        if (!str_contains($host, '.') || preg_match('/\.(local|lan|internal|home|corp|intranet)$/', $host)) {
            return 'private';
        }

        return 'public';
    }

    /** sha256 of the lower-cased host of APP_URL. */
    public function hostHash(): string
    {
        return hash('sha256', $this->host());
    }

    private function host(): string
    {
        $host = parse_url(trim($this->appUrl), \PHP_URL_HOST);

        return \is_string($host) ? strtolower(trim($host, '[]')) : '';
    }
}
