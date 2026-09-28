<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Turns the configured OIDC scope list into the scope string sent at login.
 *
 * `offline_access` is the usual refresh-token scope (Keycloak, Microsoft, Auth0).
 * Some providers reject that name: Kinde grants refresh tokens with `offline`
 * and answers `invalid_scope` when a client asks for `offline_access`.
 * When the discovery document lists `offline` and not `offline_access`, the
 * request uses `offline` instead. Other scopes that the document does not
 * advertise are kept, because an incomplete `scopes_supported` list must not
 * drop a permission the client is actually allowed to request.
 */
final class OidcScopeResolver
{
    public const DEFAULT_SCOPES = 'openid email profile offline_access';

    public const CODE_INVALID_SCOPE = 'oidc_invalid_scope';

    public const CODE_ACCESS_DENIED = 'oidc_access_denied';

    public const CODE_FAILED = 'oidc_auth_failed';

    /**
     * @param list<mixed>|null $scopesSupported
     *
     * @return array{scopes: string, replaced: list<string>}
     */
    public function resolve(string $configured, ?array $scopesSupported): array
    {
        $requested = $this->tokens($configured);
        if ([] === $requested) {
            $requested = $this->tokens(self::DEFAULT_SCOPES);
        }

        $supported = $this->supportedSet($scopesSupported);
        if ([] === $supported) {
            return [
                'scopes' => implode(' ', $requested),
                'replaced' => [],
            ];
        }

        $resolved = [];
        $replaced = [];
        foreach ($requested as $scope) {
            $key = strtolower($scope);
            if (isset($supported[$key])) {
                $resolved[] = $scope;
                continue;
            }

            if ('offline_access' === $key && isset($supported['offline'])) {
                $resolved[] = 'offline';
                $replaced[] = 'offline_access';
                continue;
            }

            $resolved[] = $scope;
        }

        return [
            'scopes' => implode(' ', array_values(array_unique($resolved))),
            'replaced' => $replaced,
        ];
    }

    public function requestsRefreshToken(string $scopes): bool
    {
        foreach ($this->tokens($scopes) as $scope) {
            $key = strtolower($scope);
            if ('offline_access' === $key || 'offline' === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * User-facing explanation of an authorization error the identity provider
     * sent back to the callback. The English sentence is the fallback for
     * clients that do not translate `code`; the web app translates `code`.
     *
     * @return array{message: string, code: string, scope: ?string}
     */
    public function describeAuthorizationError(string $error, ?string $description): array
    {
        $code = strtolower(trim($error));

        if ('invalid_scope' === $code) {
            $scope = $this->extractRejectedScope($description);
            if (null !== $scope) {
                return [
                    'message' => sprintf(
                        'Sign-in did not start. The identity provider refused the permission "%s". An administrator must allow that permission for this login, or change the requested permissions. Nothing was signed in.',
                        $scope,
                    ),
                    'code' => self::CODE_INVALID_SCOPE,
                    'scope' => $scope,
                ];
            }

            return [
                'message' => 'Sign-in did not start. The identity provider refused a requested permission. An administrator must allow the requested permissions for this login, or change them. Nothing was signed in.',
                'code' => self::CODE_INVALID_SCOPE,
                'scope' => null,
            ];
        }

        if ('access_denied' === $code) {
            return [
                'message' => 'Sign-in was cancelled. Nothing was signed in.',
                'code' => self::CODE_ACCESS_DENIED,
                'scope' => null,
            ];
        }

        return [
            'message' => 'Sign-in did not finish. Nothing was signed in. Try again, or ask an administrator to check the enterprise login.',
            'code' => self::CODE_FAILED,
            'scope' => null,
        ];
    }

    /**
     * @return list<string>
     */
    private function tokens(string $configured): array
    {
        $parts = preg_split('/\s+/', trim($configured)) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            if ('' === $part) {
                continue;
            }
            $tokens[] = $part;
        }

        return $tokens;
    }

    /**
     * @param list<mixed>|null $scopesSupported
     *
     * @return array<string, true>
     */
    private function supportedSet(?array $scopesSupported): array
    {
        if (null === $scopesSupported) {
            return [];
        }

        $supported = [];
        foreach ($scopesSupported as $scope) {
            if (!is_string($scope)) {
                continue;
            }
            $scope = strtolower(trim($scope));
            if ('' === $scope) {
                continue;
            }
            $supported[$scope] = true;
        }

        return $supported;
    }

    private function extractRejectedScope(?string $description): ?string
    {
        if (null === $description || '' === trim($description)) {
            return null;
        }

        if (1 !== preg_match("/scope\\s+['\"]([A-Za-z0-9_.:\\/-]{1,128})['\"]/i", $description, $matches)) {
            return null;
        }

        return $matches[1];
    }
}
