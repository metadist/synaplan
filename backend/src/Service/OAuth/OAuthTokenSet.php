<?php

declare(strict_types=1);

namespace App\Service\OAuth;

/**
 * One user's tokens for one connection.
 *
 * Serialized as JSON into the encrypted credential vault, so this shape IS the
 * storage format: adding a field must stay backward compatible with rows
 * written by an earlier release ({@see fromJson} tolerates missing keys).
 */
final readonly class OAuthTokenSet
{
    /**
     * @param list<string> $scopes
     * @param int          $expiresAt Unix timestamp; 0 when the server sent no expiry
     */
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public int $expiresAt,
        public array $scopes = [],
    ) {
    }

    /**
     * Build from a token endpoint response.
     *
     * A refresh response commonly omits `refresh_token` (the existing one stays
     * valid), so the caller passes the previous value as the fallback — losing
     * it would silently turn a working connection into a one-hour connection.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromTokenResponse(array $payload, string $fallbackRefreshToken = '', ?int $now = null): self
    {
        $now ??= time();

        $accessToken = is_string($payload['access_token'] ?? null) ? $payload['access_token'] : '';
        if ('' === $accessToken) {
            throw new OAuthException('Token response contained no access_token');
        }

        $refreshToken = is_string($payload['refresh_token'] ?? null) && '' !== $payload['refresh_token']
            ? $payload['refresh_token']
            : $fallbackRefreshToken;

        $expiresIn = is_numeric($payload['expires_in'] ?? null) ? (int) $payload['expires_in'] : 0;
        $scope = is_string($payload['scope'] ?? null) ? $payload['scope'] : '';

        return new self(
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresAt: $expiresIn > 0 ? $now + $expiresIn : 0,
            scopes: '' === trim($scope) ? [] : array_values(array_filter(explode(' ', $scope))),
        );
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new OAuthException('Stored OAuth token set is not readable JSON', 0, $e);
        }

        if (!is_array($data)) {
            throw new OAuthException('Stored OAuth token set is not an object');
        }

        $scopes = [];
        if (isset($data['scopes']) && is_array($data['scopes'])) {
            $scopes = array_values(array_filter($data['scopes'], is_string(...)));
        }

        return new self(
            accessToken: is_string($data['access_token'] ?? null) ? $data['access_token'] : '',
            refreshToken: is_string($data['refresh_token'] ?? null) ? $data['refresh_token'] : '',
            expiresAt: is_numeric($data['expires_at'] ?? null) ? (int) $data['expires_at'] : 0,
            scopes: $scopes,
        );
    }

    public function toJson(): string
    {
        return json_encode([
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_at' => $this->expiresAt,
            'scopes' => $this->scopes,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Expired, or close enough that a request started now could arrive after
     * expiry. An unknown expiry (0) counts as expired so the first use
     * refreshes rather than sending a token the server may already reject.
     */
    public function isExpired(int $skewSeconds = 0, ?int $now = null): bool
    {
        if (0 === $this->expiresAt) {
            return true;
        }

        return ($now ?? time()) >= ($this->expiresAt - $skewSeconds);
    }

    public function hasRefreshToken(): bool
    {
        return '' !== $this->refreshToken;
    }

    public function withTokens(self $refreshed): self
    {
        return new self(
            accessToken: $refreshed->accessToken,
            refreshToken: '' !== $refreshed->refreshToken ? $refreshed->refreshToken : $this->refreshToken,
            expiresAt: $refreshed->expiresAt,
            scopes: [] !== $refreshed->scopes ? $refreshed->scopes : $this->scopes,
        );
    }
}
