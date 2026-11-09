<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server\Grant;

use App\Service\OAuth\Server\ClientAssertionVerifier;
use League\OAuth2\Server\Grant\RefreshTokenGrant as LeagueRefreshTokenGrant;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;

/**
 * The refresh token grant, with the client proven by private_key_jwt.
 */
final class RefreshTokenGrant extends LeagueRefreshTokenGrant
{
    use AuthenticatesWithClientAssertion;

    public function __construct(
        RefreshTokenRepositoryInterface $refreshTokens,
        ClientAssertionVerifier $assertions,
        string $tokenEndpoint,
    ) {
        parent::__construct($refreshTokens);
        $this->assertions = $assertions;
        $this->tokenEndpoint = $tokenEndpoint;
    }
}
