<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server\Grant;

use App\Service\OAuth\Server\ClientAssertionVerifier;
use App\Service\OAuth\Server\CodeRedemption;
use App\Service\OAuth\Server\GrantContext;
use League\OAuth2\Server\Grant\AuthCodeGrant as LeagueAuthCodeGrant;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ServerRequestInterface;

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
        GrantContext $context,
        private readonly CodeRedemption $redemption,
    ) {
        parent::__construct($authCodes, $refreshTokens, $authCodeTtl);
        $this->assertions = $assertions;
        $this->tokenEndpoint = $tokenEndpoint;
        $this->context = $context;
    }

    public function respondToAccessTokenRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface $responseType,
        \DateInterval $accessTokenTTL,
    ): ResponseTypeInterface {
        $response = parent::respondToAccessTokenRequest($request, $responseType, $accessTokenTTL);
        $this->redemption->onCodeRedeemed($request);

        return $response;
    }
}
