<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Service\Security\SsrfGuard;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * RFC 7591 dynamic client registration against a discovered MCP authorization
 * server. Registers a public client (`token_endpoint_auth_method: none`) so
 * the install needs no pre-created vendor app.
 */
final readonly class McpOAuthRegistration
{
    private const TIMEOUT_SECONDS = 15;
    private const CLIENT_NAME = 'Synaplan';

    public function __construct(
        private HttpClientInterface $httpClient,
        private SsrfGuard $ssrfGuard,
    ) {
    }

    /**
     * @param list<string> $scopes
     *
     * @return array{client_id: string, supports_refresh: bool}
     *
     * @throws McpOAuthException
     */
    public function register(string $registrationEndpoint, string $redirectUri, string $clientUri, array $scopes): array
    {
        if ($this->ssrfGuard->isBlockedUrl($registrationEndpoint)) {
            throw new McpOAuthException('Registration endpoint is not allowed');
        }

        $body = [
            'client_name' => self::CLIENT_NAME,
            'client_uri' => $clientUri,
            'redirect_uris' => [$redirectUri],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ];
        if ([] !== $scopes) {
            $body['scope'] = implode(' ', $scopes);
        }

        try {
            $response = $this->httpClient->request('POST', $registrationEndpoint, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => $body,
                'timeout' => self::TIMEOUT_SECONDS,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $raw = $response->getContent(false);
        } catch (\Throwable $e) {
            throw new McpOAuthException('Could not register with the sign-in server: '.$e->getMessage(), 0, $e);
        }

        if ($status < 200 || $status >= 300) {
            throw new McpOAuthException(sprintf('Sign-in registration failed (HTTP %d)', $status));
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !is_string($decoded['client_id'] ?? null) || '' === $decoded['client_id']) {
            throw new McpOAuthException('Sign-in registration returned no client id');
        }

        $grants = $decoded['grant_types'] ?? [];
        $supportsRefresh = is_array($grants) && in_array('refresh_token', $grants, true);

        return [
            'client_id' => $decoded['client_id'],
            'supports_refresh' => $supportsRefresh,
        ];
    }
}
