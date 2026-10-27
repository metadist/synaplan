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
    public const MAX_DURATION_SECONDS = 10;
    public const MAX_BODY_BYTES = 262_144;
    private const MISSING_STATUSES = [404, 410];

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
            throw new McpOAuthException('MCP server URL is not allowed (private or invalid target)', McpOAuthFailure::AddressNotAllowed);
        }
        self::requireHttps($mcpUrl, 'The MCP server address');

        $prm = $this->fetchProtectedResourceMetadata($mcpUrl);

        // RFC 9728 §3.3: the metadata must describe this server, not another one.
        $resource = is_string($prm['resource'] ?? null) && '' !== $prm['resource'] ? $prm['resource'] : $mcpUrl;
        if (!self::resourceCovers($resource, $mcpUrl)) {
            throw new McpOAuthException('The sign-in metadata names a different server than this address', McpOAuthFailure::MetadataInvalid);
        }

        $authServers = $prm['authorization_servers'] ?? [];
        if (!is_array($authServers) || [] === $authServers || !is_string($authServers[0] ?? null) || '' === $authServers[0]) {
            throw new McpOAuthException('The server did not advertise an authorization server', McpOAuthFailure::NoSignIn);
        }

        $issuer = $authServers[0];
        if ($this->ssrfGuard->isBlockedUrl($issuer)) {
            throw new McpOAuthException('Authorization server URL is not allowed', McpOAuthFailure::AddressNotAllowed);
        }
        self::requireHttps($issuer, 'The authorization server');

        $asUrl = rtrim($issuer, '/').'/.well-known/oauth-authorization-server';
        $as = $this->getJson($asUrl, 'authorization server metadata');

        // RFC 8414 §3.3: the metadata must be the named issuer's own.
        $advertisedIssuer = is_string($as['issuer'] ?? null) ? $as['issuer'] : '';
        if ($advertisedIssuer !== $issuer) {
            throw new McpOAuthException('The authorization server metadata belongs to a different issuer', McpOAuthFailure::MetadataInvalid);
        }

        $authorize = is_string($as['authorization_endpoint'] ?? null) ? $as['authorization_endpoint'] : '';
        $token = is_string($as['token_endpoint'] ?? null) ? $as['token_endpoint'] : '';
        $register = is_string($as['registration_endpoint'] ?? null) ? $as['registration_endpoint'] : '';
        if ('' === $authorize || '' === $token) {
            throw new McpOAuthException('Authorization server is missing required endpoints', McpOAuthFailure::MetadataInvalid);
        }
        if ('' === $register) {
            throw new McpOAuthException('This server does not support automatic app registration', McpOAuthFailure::NoRegistration);
        }

        foreach ([$authorize, $token, $register] as $endpoint) {
            if ($this->ssrfGuard->isBlockedUrl($endpoint)) {
                throw new McpOAuthException('An OAuth endpoint on this server is not allowed', McpOAuthFailure::AddressNotAllowed);
            }
            self::requireHttps($endpoint, 'An OAuth endpoint on this server');
        }

        $methods = $as['code_challenge_methods_supported'] ?? [];
        if (!is_array($methods) || !in_array('S256', $methods, true)) {
            throw new McpOAuthException('This server does not support the required sign-in method (S256)', McpOAuthFailure::UnsupportedMethod);
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
            issuer: $issuer,
        );
    }

    /**
     * Request options every sign-in fetch shares: no redirects, a hard total
     * duration and a body cap, so a slow or oversized answer cannot hold a
     * PHP worker.
     *
     * @return array<string, mixed>
     */
    public static function boundedOptions(): array
    {
        return [
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::MAX_DURATION_SECONDS,
            'max_redirects' => 0,
            'on_progress' => static function (int $downloaded, int $total): void {
                if ($downloaded > self::MAX_BODY_BYTES || $total > self::MAX_BODY_BYTES) {
                    throw new McpOAuthException('The sign-in server sent a response that is too large', McpOAuthFailure::MetadataInvalid);
                }
            },
        ];
    }

    /**
     * The HTTP client wraps an exception thrown from `on_progress`; keep the
     * reason it carried instead of reporting "unreachable".
     */
    public static function reasonFor(\Throwable $e, McpOAuthFailure $fallback): McpOAuthFailure
    {
        for ($cause = $e; null !== $cause; $cause = $cause->getPrevious()) {
            if ($cause instanceof McpOAuthException) {
                return $cause->reason;
            }
        }

        return $fallback;
    }

    /**
     * @throws McpOAuthException
     */
    public static function requireHttps(string $url, string $what): void
    {
        if ('https' !== strtolower((string) parse_url($url, PHP_URL_SCHEME))) {
            throw new McpOAuthException($what.' must use https for sign-in', McpOAuthFailure::HttpsRequired);
        }
    }

    private static function resourceCovers(string $resource, string $mcpUrl): bool
    {
        $r = parse_url($resource);
        $m = parse_url($mcpUrl);
        if (!is_array($r) || !is_array($m)) {
            return false;
        }

        $originOf = static function (array $p): string {
            $scheme = strtolower((string) ($p['scheme'] ?? ''));

            return $scheme.'://'.strtolower((string) ($p['host'] ?? '')).':'.($p['port'] ?? ('https' === $scheme ? 443 : 80));
        };
        if ($originOf($r) !== $originOf($m)) {
            return false;
        }

        $resourcePath = rtrim($r['path'] ?? '', '/');
        $mcpPath = rtrim($m['path'] ?? '', '/');

        return '' === $resourcePath || $mcpPath === $resourcePath || str_starts_with($mcpPath, $resourcePath.'/');
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchProtectedResourceMetadata(string $mcpUrl): array
    {
        $challengeUrl = $this->probeResourceMetadata($mcpUrl);
        if (null !== $challengeUrl) {
            return $this->getJson($challengeUrl, 'protected resource metadata', McpOAuthFailure::NoSignIn);
        }

        $parts = parse_url($mcpUrl);
        $scheme = is_string($parts['scheme'] ?? null) ? $parts['scheme'] : 'https';
        $host = is_string($parts['host'] ?? null) ? $parts['host'] : '';
        $path = is_string($parts['path'] ?? null) ? $parts['path'] : '';
        if ('' === $host) {
            throw new McpOAuthException('MCP server URL is not a valid HTTP address', McpOAuthFailure::AddressNotAllowed);
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

        // A missing document means "try the next place"; a server that did not
        // answer or answered with something broken is the more useful sentence.
        $failure = null;
        foreach ($candidates as $candidate) {
            if ($this->ssrfGuard->isBlockedUrl($candidate)) {
                continue;
            }
            try {
                return $this->getJson($candidate, 'protected resource metadata', McpOAuthFailure::NoSignIn);
            } catch (McpOAuthException $e) {
                if (McpOAuthFailure::NoSignIn !== $e->reason) {
                    $failure ??= $e;
                }
            }
        }

        throw $failure ?? new McpOAuthException('Could not find sign-in information for this MCP server', McpOAuthFailure::NoSignIn);
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
            ] + self::boundedOptions());
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
     * @param McpOAuthFailure $missing reason when the document is not there (404, 410)
     *
     * @return array<string, mixed>
     */
    private function getJson(string $url, string $label, McpOAuthFailure $missing = McpOAuthFailure::MetadataInvalid): array
    {
        self::requireHttps($url, 'The '.$label.' address');
        if ($this->ssrfGuard->isBlockedUrl($url)) {
            throw new McpOAuthException(sprintf('The %s URL is not allowed', $label), McpOAuthFailure::AddressNotAllowed);
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['Accept' => 'application/json'],
            ] + self::boundedOptions());
            $status = $response->getStatusCode();
            $raw = $response->getContent(false);
        } catch (\Throwable $e) {
            throw new McpOAuthException(sprintf('Could not load %s: %s', $label, $e->getMessage()), self::reasonFor($e, McpOAuthFailure::Unreachable), $e);
        }

        if ($status < 200 || $status >= 300) {
            $reason = match (true) {
                in_array($status, self::MISSING_STATUSES, true) => $missing,
                $status >= 500 => McpOAuthFailure::Unreachable,
                default => McpOAuthFailure::MetadataInvalid,
            };

            throw new McpOAuthException(sprintf('Could not load %s (HTTP %d)', $label, $status), $reason);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new McpOAuthException(sprintf('The %s document is not valid JSON', $label), McpOAuthFailure::MetadataInvalid);
        }

        return $decoded;
    }
}
