<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Authorization-code + PKCE client against any OAuth2 token endpoint.
 *
 * Deliberately stateless and free of Symfony Security: the same instance serves
 * the interactive consent callback and the scheduler's unattended refresh
 * (connector plan 07 F3). Nothing here reads a session, a cookie or a current
 * user — callers pass everything in.
 */
final readonly class OAuthClient
{
    private const TIMEOUT_SECONDS = 15;

    /**
     * `invalid_grant` is the spec's "this grant is dead" answer and is the only
     * error that must never be retried. Microsoft additionally sends
     * `interaction_required` / `consent_required` when an admin revoked the app.
     */
    private const REAUTH_ERRORS = ['invalid_grant', 'interaction_required', 'consent_required', 'login_required'];

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * PKCE verifier: 43–128 unreserved characters (RFC 7636 §4.1).
     */
    public function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    public function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * Consent URL the browser is sent to. `prompt=consent` is deliberate on the
     * first grant: without it Microsoft may silently reuse an existing consent
     * that lacks `offline_access`, leaving us with no refresh token.
     */
    public function authorizationUrl(OAuthProviderConfig $provider, string $state, string $codeChallenge): string
    {
        $query = [
            'client_id' => $provider->clientId,
            'response_type' => 'code',
            'redirect_uri' => $provider->redirectUri,
            'response_mode' => 'query',
            'scope' => $provider->scopeString(),
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'consent',
        ];

        return $provider->authorizeUrl.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(OAuthProviderConfig $provider, string $code, string $codeVerifier): OAuthTokenSet
    {
        $payload = $this->post($provider, [
            'client_id' => $provider->clientId,
            'client_secret' => $provider->clientSecret,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $provider->redirectUri,
            'code_verifier' => $codeVerifier,
            'scope' => $provider->scopeString(),
        ]);

        return OAuthTokenSet::fromTokenResponse($payload);
    }

    /**
     * @throws OAuthReauthRequiredException when the refresh token is no longer usable
     */
    public function refresh(OAuthProviderConfig $provider, string $refreshToken): OAuthTokenSet
    {
        if ('' === $refreshToken) {
            throw new OAuthReauthRequiredException('No refresh token stored for this connection');
        }

        $payload = $this->post($provider, [
            'client_id' => $provider->clientId,
            'client_secret' => $provider->clientSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'scope' => $provider->scopeString(),
        ]);

        return OAuthTokenSet::fromTokenResponse($payload, $refreshToken);
    }

    /**
     * @param array<string, string> $body
     *
     * @return array<string, mixed>
     */
    private function post(OAuthProviderConfig $provider, array $body): array
    {
        try {
            $response = $this->httpClient->request('POST', $provider->tokenUrl, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => $body,
                'timeout' => self::TIMEOUT_SECONDS,
                'max_redirects' => 0,
            ]);

            $status = $response->getStatusCode();
            $raw = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new OAuthException(sprintf('Could not reach the %s token endpoint: %s', $provider->provider, $e->getMessage()), 0, $e);
        }

        $decoded = json_decode($raw, true);
        $decoded = is_array($decoded) ? $decoded : [];

        if ($status >= 200 && $status < 300) {
            return $decoded;
        }

        $error = is_string($decoded['error'] ?? null) ? $decoded['error'] : '';
        $description = is_string($decoded['error_description'] ?? null) ? $decoded['error_description'] : '';

        // The description carries the account/tenant and correlation ids; the
        // tokens live in the request body, never in the response, so this is
        // safe to log while the body is not.
        $this->logger->warning('OAuth token endpoint rejected the request', [
            'provider' => $provider->provider,
            'grant_type' => $body['grant_type'] ?? 'unknown',
            'status' => $status,
            'error' => $error,
        ]);

        $message = sprintf(
            '%s token endpoint answered HTTP %d%s',
            $provider->provider,
            $status,
            '' !== $error ? sprintf(' (%s: %s)', $error, $description) : '',
        );

        if (in_array($error, self::REAUTH_ERRORS, true)) {
            throw new OAuthReauthRequiredException($message);
        }

        throw new OAuthException($message);
    }
}
