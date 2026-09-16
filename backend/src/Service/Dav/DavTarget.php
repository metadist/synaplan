<?php

declare(strict_types=1);

namespace App\Service\Dav;

/**
 * One WebDAV/CalDAV endpoint with its Basic-auth credential (an app password,
 * never the account password — connector plan 07 C10/C12).
 */
final readonly class DavTarget
{
    public function __construct(
        public string $baseUrl,
        public string $username,
        public string $password,
    ) {
    }

    /** Hostname for user-facing copy ("delivered to cloud.example.com"). */
    public function host(): string
    {
        $host = parse_url($this->baseUrl, \PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }
}
