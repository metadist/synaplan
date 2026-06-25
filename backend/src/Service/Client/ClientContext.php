<?php

declare(strict_types=1);

namespace App\Service\Client;

/**
 * Server-confirmed identity of the calling client (Aspect 1 / mobile app Epic 2).
 *
 * Derived from the request User-Agent: the native app appends a frozen
 * `Synaplan Mobile V<major>.<minor>[.<patch>]` token (see synaplan-apps
 * docs/IDENTIFIERS.md). This is an identity HINT for branding/analytics and the
 * forced-update gate — it is trivially spoofable and must NEVER be used as an
 * authorization control. Security always rests on the Bearer token + server-side
 * validation.
 */
final readonly class ClientContext
{
    public function __construct(
        public bool $isMobileApp,
        public ?string $appVersion,
        public ?int $appVersionMajor,
        public ?int $appVersionMinor,
        public ?int $appVersionPatch,
    ) {
    }

    /**
     * The non-app default (browser / API client).
     */
    public static function web(): self
    {
        return new self(false, null, null, null, null);
    }

    public function platform(): string
    {
        return $this->isMobileApp ? 'mobile' : 'web';
    }
}
