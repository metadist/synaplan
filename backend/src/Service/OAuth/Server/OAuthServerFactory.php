<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server;

use App\Service\OAuth\Server\Grant\AuthCodeGrant;
use App\Service\OAuth\Server\Grant\RefreshTokenGrant;
use App\Service\OAuth\Server\Repository\AccessTokenRepository;
use App\Service\OAuth\Server\Repository\AuthCodeRepository;
use App\Service\OAuth\Server\Repository\ClientRepository;
use App\Service\OAuth\Server\Repository\RefreshTokenRepository;
use App\Service\OAuth\Server\Repository\ScopeRepository;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;

/**
 * Synaplan's OAuth2 authorization server for clouds: authorization code
 * with PKCE for a person, refresh tokens that rotate. Access tokens live 15
 * minutes; a refresh token 90 days from its last use.
 */
final readonly class OAuthServerFactory
{
    public const ACCESS_TOKEN_TTL = 'PT15M';
    public const REFRESH_TOKEN_TTL = 'P90D';
    public const AUTH_CODE_TTL = 'PT5M';

    public function __construct(
        private ClientRepository $clients,
        private AccessTokenRepository $accessTokens,
        private ScopeRepository $scopes,
        private AuthCodeRepository $authCodes,
        private RefreshTokenRepository $refreshTokens,
        private AccessTokenKeyStore $keys,
        private ClientAssertionVerifier $assertions,
        private TokenCipher $cipher,
        private CodeRedemption $redemption,
        private string $issuer,
    ) {
    }

    public function accessTokens(): AccessTokenRepository
    {
        return $this->accessTokens;
    }

    public function create(): AuthorizationServer
    {
        $server = new AuthorizationServer(
            $this->clients,
            $this->accessTokens,
            $this->scopes,
            new CryptKey($this->keys->privateKeyPem(), null, false),
            $this->encryptionKey(),
        );
        $tokenEndpoint = rtrim($this->issuer, '/').'/oauth/token';
        $authCode = new AuthCodeGrant($this->authCodes, $this->refreshTokens, new \DateInterval(self::AUTH_CODE_TTL), $this->assertions, $tokenEndpoint, $this->redemption->context(), $this->redemption);
        $authCode->setRefreshTokenTTL(new \DateInterval(self::REFRESH_TOKEN_TTL));
        $server->enableGrantType($authCode, new \DateInterval(self::ACCESS_TOKEN_TTL));
        $refresh = new RefreshTokenGrant($this->refreshTokens, $this->assertions, $tokenEndpoint, $this->redemption->context());
        $refresh->setRefreshTokenTTL(new \DateInterval(self::REFRESH_TOKEN_TTL));
        $server->enableGrantType($refresh, new \DateInterval(self::ACCESS_TOKEN_TTL));

        return $server;
    }

    /**
     * The key league encrypts codes and refresh tokens with, derived from the
     * app secret so it survives restarts and is the same on every node.
     */
    public function encryptionKey(): string
    {
        return $this->cipher->key();
    }
}
