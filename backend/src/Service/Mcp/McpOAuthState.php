<?php

declare(strict_types=1);

namespace App\Service\Mcp;

/**
 * Encrypted-at-rest OAuth blob on {@see \App\Entity\McpServerConfig}.
 *
 * Adding a field must stay backward compatible — {@see fromArray()} tolerates
 * missing keys so rows written by an earlier release still load.
 *
 * @phpstan-type OAuthStateArray array{
 *     resource?: string,
 *     authorization_endpoint?: string,
 *     token_endpoint?: string,
 *     registration_endpoint?: string,
 *     client_id?: string,
 *     scopes?: list<string>,
 *     access_token?: string,
 *     refresh_token?: string,
 *     expires_at?: int,
 *     status?: string,
 *     supports_refresh?: bool
 * }
 */
final readonly class McpOAuthState
{
    public const STATUS_NOT_CONNECTED = 'not_connected';
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_REAUTH_REQUIRED = 'reauth_required';

    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public string $resource = '',
        public string $authorizationEndpoint = '',
        public string $tokenEndpoint = '',
        public string $registrationEndpoint = '',
        public string $clientId = '',
        public array $scopes = [],
        public string $accessToken = '',
        public string $refreshToken = '',
        public int $expiresAt = 0,
        public string $status = self::STATUS_NOT_CONNECTED,
        public bool $supportsRefresh = true,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $scopes = [];
        if (isset($data['scopes']) && is_array($data['scopes'])) {
            $scopes = array_values(array_filter($data['scopes'], is_string(...)));
        }

        $status = is_string($data['status'] ?? null) ? $data['status'] : self::STATUS_NOT_CONNECTED;
        if (!in_array($status, [self::STATUS_NOT_CONNECTED, self::STATUS_CONNECTED, self::STATUS_REAUTH_REQUIRED], true)) {
            $status = self::STATUS_NOT_CONNECTED;
        }

        return new self(
            resource: is_string($data['resource'] ?? null) ? $data['resource'] : '',
            authorizationEndpoint: is_string($data['authorization_endpoint'] ?? null) ? $data['authorization_endpoint'] : '',
            tokenEndpoint: is_string($data['token_endpoint'] ?? null) ? $data['token_endpoint'] : '',
            registrationEndpoint: is_string($data['registration_endpoint'] ?? null) ? $data['registration_endpoint'] : '',
            clientId: is_string($data['client_id'] ?? null) ? $data['client_id'] : '',
            scopes: $scopes,
            accessToken: is_string($data['access_token'] ?? null) ? $data['access_token'] : '',
            refreshToken: is_string($data['refresh_token'] ?? null) ? $data['refresh_token'] : '',
            expiresAt: is_numeric($data['expires_at'] ?? null) ? (int) $data['expires_at'] : 0,
            status: $status,
            supportsRefresh: array_key_exists('supports_refresh', $data) ? (bool) $data['supports_refresh'] : true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'resource' => $this->resource,
            'authorization_endpoint' => $this->authorizationEndpoint,
            'token_endpoint' => $this->tokenEndpoint,
            'registration_endpoint' => $this->registrationEndpoint,
            'client_id' => $this->clientId,
            'scopes' => $this->scopes,
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_at' => $this->expiresAt,
            'status' => $this->status,
            'supports_refresh' => $this->supportsRefresh,
        ];
    }

    public function hasAccessToken(): bool
    {
        return '' !== $this->accessToken;
    }

    public function hasRefreshToken(): bool
    {
        return '' !== $this->refreshToken;
    }

    public function isExpired(int $skewSeconds = 60, ?int $now = null): bool
    {
        if (0 === $this->expiresAt) {
            return $this->hasAccessToken();
        }

        return ($now ?? time()) >= ($this->expiresAt - $skewSeconds);
    }

    public function withTokens(string $accessToken, string $refreshToken, int $expiresAt, string $status = self::STATUS_CONNECTED): self
    {
        return new self(
            resource: $this->resource,
            authorizationEndpoint: $this->authorizationEndpoint,
            tokenEndpoint: $this->tokenEndpoint,
            registrationEndpoint: $this->registrationEndpoint,
            clientId: $this->clientId,
            scopes: $this->scopes,
            accessToken: $accessToken,
            refreshToken: '' !== $refreshToken ? $refreshToken : $this->refreshToken,
            expiresAt: $expiresAt,
            status: $status,
            supportsRefresh: $this->supportsRefresh,
        );
    }

    public function withoutTokens(string $status = self::STATUS_NOT_CONNECTED): self
    {
        return new self(
            resource: $this->resource,
            authorizationEndpoint: $this->authorizationEndpoint,
            tokenEndpoint: $this->tokenEndpoint,
            registrationEndpoint: $this->registrationEndpoint,
            clientId: $this->clientId,
            scopes: $this->scopes,
            accessToken: '',
            refreshToken: '',
            expiresAt: 0,
            status: $status,
            supportsRefresh: $this->supportsRefresh,
        );
    }

    public function withStatus(string $status): self
    {
        return new self(
            resource: $this->resource,
            authorizationEndpoint: $this->authorizationEndpoint,
            tokenEndpoint: $this->tokenEndpoint,
            registrationEndpoint: $this->registrationEndpoint,
            clientId: $this->clientId,
            scopes: $this->scopes,
            accessToken: $this->accessToken,
            refreshToken: $this->refreshToken,
            expiresAt: $this->expiresAt,
            status: $status,
            supportsRefresh: $this->supportsRefresh,
        );
    }
}
