<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server;

use App\Repository\OAuthTokenRepository;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Checks an access token Synaplan issued: RS256 with its own key, typ
 * at+jwt, iss Synaplan, not expired, not revoked.
 */
final readonly class AccessTokenValidator
{
    public function __construct(
        private AccessTokenKeyStore $keys,
        private OAuthTokenRepository $tokens,
        private string $issuer,
    ) {
    }

    /**
     * Whether the bearer looks like one of Synaplan's own access tokens, so
     * no other authenticator tries it. Nothing is verified here.
     */
    public function isOurs(string $token): bool
    {
        $parts = explode('.', $token);
        if (3 !== count($parts)) {
            return false;
        }
        $header = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);

        return is_array($header) && 'at+jwt' === ($header['typ'] ?? null)
            && is_array($payload) && rtrim($this->issuer, '/') === ($payload['iss'] ?? null);
    }

    /**
     * @return array{jti: string, client_id: string, user_id: int|null, scopes: list<string>, external_id: string}|null
     */
    public function validate(string $token, bool $checkRevoked = true): ?array
    {
        if (!$this->isOurs($token)) {
            return null;
        }
        try {
            $claims = (array) JWT::decode($token, new Key($this->keys->publicKeyPem(), 'RS256'));
        } catch (\Throwable) {
            return null;
        }
        $jti = $claims['jti'] ?? null;
        $client = $claims['client_id'] ?? null;
        if (!is_string($jti) || !is_string($client) || ($claims['aud'] ?? null) !== $client) {
            return null;
        }
        if ($checkRevoked && $this->tokens->isRevoked($jti)) {
            return null;
        }
        $sub = $claims['sub'] ?? null;
        $externalId = $claims['external_id'] ?? null;
        if (!is_string($externalId) || '' === $externalId) {
            return null;
        }
        $scope = is_string($claims['scope'] ?? null) ? $claims['scope'] : '';

        return [
            'jti' => $jti,
            'client_id' => $client,
            'user_id' => is_string($sub) && ctype_digit($sub) ? (int) $sub : null,
            'scopes' => array_values(array_filter(explode(' ', $scope), static fn (string $s): bool => '' !== $s)),
            'external_id' => $externalId,
        ];
    }
}
