<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Service\Http\ClientAddress;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A hosted install can refuse a new account unless Cloudflare is the hop in
 * front of this deployment's own addresses.
 *
 * The public default is off, so a self-hosted install is unchanged. Callers
 * run this only when they are about to create an account. An existing account
 * still signs in. Directory provisioning and accounts an operator creates do
 * not come through here.
 *
 * A CF-Connecting-IP header is not proof. The address immediately in front of
 * the load balancer (and the proxy that connected to PHP) has to be a
 * published Cloudflare edge. The edge proxy writes that chain. Copying the
 * visitor's own header would let them name the hop.
 */
final readonly class SignupEdge
{
    public const ENV_REQUIRE = 'SIGNUP_REQUIRE_CLOUDFLARE';
    public const ENV_HOPS = 'SIGNUP_EDGE_TRUSTED_HOPS';

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
        if (!self::required() || ClientAddress::hopInFrontIsCloudflare($request, self::ownHops())) {
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

    private static function ownHops(): string
    {
        return trim((string) ($_ENV[self::ENV_HOPS] ?? ''));
    }
}
