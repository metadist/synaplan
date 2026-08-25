<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Service\Security\SsrfGuard;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * RFC 9728 protected-resource metadata + RFC 8414 authorization-server
 * metadata for a remote MCP URL (Notion, Higgsfield, any spec-compliant host).
 *
 * Tries the 401 `resource_metadata=` challenge first, then the path-suffix
 * well-known form (`/.well-known/oauth-protected-resource/mcp`) and the root
 * form. Every discovered URL is SSRF-checked before it is fetched.
 */
final readonly class McpOAuthDiscovery
{
    private const TIMEOUT_SECONDS = 15;

    public function __construct(
        private HttpClientInterface $httpClient,
        private SsrfGuard $ssrfGuard,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws McpOAuthException
     */
    public function discover(string $mcpUrl): McpOAuthDiscoveryResult
    {
        $mcpUrl = rtrim(trim($mcpUrl), '/');
        if ($this->ssrfGuard->isBlockedUrl($mcpUrl)) {
            throw new McpOAuthException('MCP server URL is not allowed (private or invalid target)');
        }

        $prm = $this->fetchProtectedResourceMetadata($mcpUrl);

        $resource = is_string($prm['resource'] ?? null) ? $prm['resource'] : $mcpUrl;
        $authServers = $prm['authorization_servers'] ?? [];
        if (!is_array($authServers) || [] === $authServers || !is_string($authServers[0] ?? null) || '' === $authServers[0]) {
            throw new McpOAuthException('The server did not advertise an authorization server');
        }

        $issuer = rtrim($authServers[0], '/');
        if ($this->ssrfGuard->isBlockedUrl($issuer)) {
            throw new McpOAuthException('Authorization server URL is not allowed');
        }

        $asUrl = $issuer.'/.well-known/oauth-authorization-server';
        $as = $this->getJson($asUrl, 'authorization server metadata');

        $authorize = is_string($as['authorization_endpoint'] ?? null) ? $as['authorization_endpoint'] : '';
        $token = is_string($as['token_endpoint'] ?? null) ? $as['token_endpoint'] : '';
        $register = is_string($as['registration_endpoint'] ?? null) ? $as['registration_endpoint'] : '';
        if ('' === $authorize || '' === $token) {
            throw new McpOAuthException('Authorization server is missing required endpoints');
        }
        if ('' === $register) {
            throw new McpOAuthException('This server does not support automatic app registration');
        }

        foreach ([$authorize, $token, $register] as $endpoint) {
            if ($this->ssrfGuard->isBlockedUrl($endpoint)) {
                throw new McpOAuthException('An OAuth endpoint on this server is not allowed');
            }
        }

        $methods = $as['code_challenge_methods_supported'] ?? [];
        if (!is_array($methods) || !in_array('S256', $methods, true)) {
            throw new McpOAuthException('This server does not support the required sign-in method (S256)');
        }

        $scopes = [];
        $rawScopes = $prm['scopes_supported'] ?? $as['scopes_supported'] ?? [];
        if (is_array($rawScopes)) {
            $scopes = array_values(array_filter($rawScopes, is_string(...)));
        }

        $grants = $as['grant_types_supported'] ?? [];
        $supportsRefresh = is_array($grants) && in_array('refresh_token', $grants, true);

        return new McpOAuthDiscoveryResult(
            resource: $resource,
            authorizationEndpoint: $authorize,
            tokenEndpoint: $token,
            registrationEndpoint: $register,
            scopes: $scopes,
            supportsRefreshGrant: $supportsRefresh,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchProtectedResourceMetadata(string $mcpUrl): array
    {
        $challengeUrl = $this->probeResourceMetadata($mcpUrl);
        if (null !== $challengeUrl) {
            return $this->getJson($challengeUrl, 'protected resource metadata');
        }

        $parts = parse_url($mcpUrl);
        $scheme = is_string($parts['scheme'] ?? null) ? $parts['scheme'] : 'https';
        $host = is_string($parts['host'] ?? null) ? $parts['host'] : '';
        $path = is_string($parts['path'] ?? null) ? $parts['path'] : '';
        if ('' === $host) {
            throw new McpOAuthException('MCP server URL is not a valid HTTP address');
        }

        $origin = $scheme.'://'.$host;
        $port = $parts['port'] ?? null;
        if (is_int($port)) {
            $origin .= ':'.$port;
        }

        $candidates = [
            $origin.'/.well-known/oauth-protected-resource'.$path,
            $origin.'/.well-known/oauth-protected-resource',
            $mcpUrl.'/.well-known/oauth-protected-resource',
        ];

        foreach ($candidates as $candidate) {
            if ($this->ssrfGuard->isBlockedUrl($candidate)) {
                continue;
            }
            try {
                return $this->getJson($candidate, 'protected resource metadata');
            } catch (McpOAuthException) {
                continue;
            }
        }

        throw new McpOAuthException('Could not find sign-in information for this MCP server');
    }

    private function probeResourceMetadata(string $mcpUrl): ?string
    {
        try {
            $response = $this->httpClient->request('POST', $mcpUrl, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json, text/event-stream',
                    'MCP-Protocol-Version' => McpClient::PROTOCOL_VERSION,
                ],
                'json' => [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'initialize',
                    'params' => [
                        'protocolVersion' => McpClient::PROTOCOL_VERSION,
                        'capabilities' => new \stdClass(),
                        'clientInfo' => ['name' => 'synaplan-mcp-client', 'version' => '1.0'],
                    ],
                ],
                'timeout' => self::TIMEOUT_SECONDS,
                'max_redirects' => 0,
            ]);
            $www = $response->getHeaders(false)['www-authenticate'][0] ?? '';
        } catch (\Throwable $e) {
            $this->logger->info('McpOAuthDiscovery: initialize probe failed', ['error' => $e->getMessage()]);

            return null;
        }

        if ('' === $www) {
            return null;
        }

        $url = '';
        if (1 === preg_match('/resource_metadata="([^"]+)"/', $www, $quoted)) {
            $url = $quoted[1];
        } elseif (1 === preg_match('/resource_metadata=([^\s,]+)/', $www, $bare)) {
            $url = $bare[1];
        }
        if ('' !== $url && !$this->ssrfGuard->isBlockedUrl($url)) {
            return $url;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $url, string $label): array
    {
        if ($this->ssrfGuard->isBlockedUrl($url)) {
            throw new McpOAuthException(sprintf('The %s URL is not allowed', $label));
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => self::TIMEOUT_SECONDS,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $raw = $response->getContent(false);
        } catch (\Throwable $e) {
            throw new McpOAuthException(sprintf('Could not load %s: %s', $label, $e->getMessage()), 0, $e);
        }

        if ($status < 200 || $status >= 300) {
            throw new McpOAuthException(sprintf('Could not load %s (HTTP %d)', $label, $status));
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new McpOAuthException(sprintf('The %s document is not valid JSON', $label));
        }

        return $decoded;
    }
}
