<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Service\Http\ClientAddress;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A hosted install can refuse a new account unless the request arrived through
 * a trusted Cloudflare hop (`SIGNUP_REQUIRE_CLOUDFLARE=true`).
 *
 * The public default is off, so a self-hosted install is unchanged. Callers
 * run this only when they are about to create an account. An existing account
 * still signs in. Directory provisioning and accounts an operator creates do
 * not come through here.
 *
 * A `CF-Connecting-IP` header is not proof. Every hop between PHP and a
 * published Cloudflare edge must sit in `SYMFONY_TRUSTED_PROXIES` (with
 * `SYMFONY_TRUSTED_HEADERS=x-forwarded-for`), and the edge proxy must write
 * that chain rather than copying a visitor header.
 */
final readonly class SignupEdge
{
    public const ENV_REQUIRE = 'SIGNUP_REQUIRE_CLOUDFLARE';

    public function __construct(
        private RequestStack $requests,
    ) {
    }

    /**
     * @throws SignupBlockedException
     */
    public function assertCurrent(): void
    {
        $request = $this->requests->getCurrentRequest();
        if (!$request instanceof Request) {
            return;
        }

        self::assertRequest($request);
    }

    /**
     * @throws SignupBlockedException
     */
    public static function assertRequest(Request $request): void
    {
        if (!self::required() || ClientAddress::cameThroughTrustedCloudflare($request)) {
            return;
        }

        throw SignupBlockedException::edgeRequired();
    }

    private static function required(): bool
    {
        $raw = trim((string) ($_ENV[self::ENV_REQUIRE] ?? ''));
        if ('' === $raw) {
            return false;
        }

        return filter_var($raw, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? false;
    }
}
