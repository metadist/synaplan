<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server\Grant;

use App\Service\OAuth\Server\ClientAssertionVerifier;
use League\OAuth2\Server\Grant\AuthCodeGrant as LeagueAuthCodeGrant;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;

/**
 * The authorization code grant, with the client proven by private_key_jwt.
 */
final class AuthCodeGrant extends LeagueAuthCodeGrant
{
    use AuthenticatesWithClientAssertion;

    public function __construct(
        AuthCodeRepositoryInterface $authCodes,
        RefreshTokenRepositoryInterface $refreshTokens,
        \DateInterval $authCodeTtl,
        ClientAssertionVerifier $assertions,
        string $tokenEndpoint,
    ) {
        parent::__construct($authCodes, $refreshTokens, $authCodeTtl);
        $this->assertions = $assertions;
        $this->tokenEndpoint = $tokenEndpoint;
    }
}
