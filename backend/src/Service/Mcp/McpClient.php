<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Entity\McpServerConfig;
use App\Service\EncryptionService;
use App\Service\Security\SsrfGuard;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Outbound MCP client over Streamable HTTP (release-4.0 plan 09 §3.2; locked
 * transport decision — SSE-only transport is deprecated).
 *
 * One short-lived session per operation: `initialize` →
 * `notifications/initialized` → the actual request → best-effort session
 * DELETE. Servers that answer with `text/event-stream` are supported by
 * parsing the JSON-RPC response out of the SSE `data:` events.
 *
 * Guard rails (plan 09 §2): every target is {@see SsrfGuard}-checked, every
 * call is timeout-bounded ({@see McpClientConfig::nodeTimeoutSeconds}),
 * responses are size-capped, and every expected failure surfaces as
 * {@see McpClientException} — callers degrade gracefully, nothing hangs a
 * user turn.
 */
final readonly class McpClient
{
    public const PROTOCOL_VERSION = '2025-11-25';

    /** Cap on a single JSON-RPC response body (tool results can be huge). */
    private const MAX_RESPONSE_BYTES = 524288; // 512 KiB

    public function __construct(
        private HttpClientInterface $httpClient,
        private SsrfGuard $ssrfGuard,
        private EncryptionService $encryptionService,
        private McpClientConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Discover the server's tools.
     *
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public function listTools(McpServerConfig $server): array
    {
        $result = $this->withSession($server, fn (string $url, array $headers): array => $this->request($url, $headers, 'tools/list', []));

        $tools = [];
        foreach ((array) ($result['tools'] ?? []) as $tool) {
            if (!is_array($tool) || !is_string($tool['name'] ?? null)) {
                continue;
            }
            $tools[] = [
                'name' => $tool['name'],
                'description' => is_string($tool['description'] ?? null) ? $tool['description'] : '',
                'inputSchema' => is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : [],
            ];
        }

        return $tools;
    }

    /**
     * Call one tool and return its result block.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{content: list<array<string, mixed>>, isError: bool}
     */
    public function callTool(McpServerConfig $server, string $tool, array $arguments): array
    {
        $result = $this->withSession(
            $server,
            fn (string $url, array $headers): array => $this->request($url, $headers, 'tools/call', [
                'name' => $tool,
                'arguments' => (object) $arguments,
            ]),
        );

        $content = [];
        foreach ((array) ($result['content'] ?? []) as $block) {
            if (is_array($block)) {
                $content[] = $block;
            }
        }

        return [
            'content' => $content,
            'isError' => (bool) ($result['isError'] ?? false),
        ];
    }

    /**
     * Run one operation inside a fresh MCP session.
     *
     * @param callable(string, array<string, string>): array<string, mixed> $operation
     *
     * @return array<string, mixed>
     */
    private function withSession(McpServerConfig $server, callable $operation): array
    {
        $url = trim($server->getUrl());
        if ($this->ssrfGuard->isBlockedUrl($url)) {
            throw new McpClientException('MCP server URL is not allowed (private or invalid target)');
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
        ];

        $authHeader = trim($server->getAuthHeader());
        if ('' !== $authHeader && $server->hasAuthToken()) {
            $headers[$authHeader] = $server->getDecryptedAuthToken($this->encryptionService);
        }

        // 1. initialize — negotiates the session; the server may hand back a
        //    session id header every subsequent request must carry.
        $initResponse = $this->post($url, $headers, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'synaplan-mcp-client', 'version' => '1.0'],
            ],
        ]);
        $this->decodeRpcResult($initResponse);

        $sessionId = $initResponse->getHeaders(false)['mcp-session-id'][0] ?? null;
        if (is_string($sessionId) && '' !== $sessionId) {
            $headers['Mcp-Session-Id'] = $sessionId;
        }

        try {
            // 2. initialized notification (no response expected).
            $this->post($url, $headers, [
                'jsonrpc' => '2.0',
                'method' => 'notifications/initialized',
            ])->getStatusCode();

            // 3. The actual operation.
            return $operation($url, $headers);
        } finally {
            // 4. Best-effort session teardown.
            if (isset($headers['Mcp-Session-Id'])) {
                try {
                    $this->httpClient->request('DELETE', $url, [
                        'headers' => $headers,
                        'timeout' => 5,
                    ])->getStatusCode();
                } catch (\Throwable) {
                    // Session cleanup is cosmetic — the server expires it anyway.
                }
            }
        }
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $params
     *
     * @return array<string, mixed>
     */
    private function request(string $url, array $headers, string $method, array $params): array
    {
        $response = $this->post($url, $headers, [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => $method,
            'params' => [] === $params ? new \stdClass() : $params,
        ]);

        return $this->decodeRpcResult($response);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $payload
     */
    private function post(string $url, array $headers, array $payload): ResponseInterface
    {
        try {
            return $this->httpClient->request('POST', $url, [
                'headers' => $headers,
                'json' => $payload,
                'timeout' => $this->config->nodeTimeoutSeconds(),
                'max_redirects' => 0,
            ]);
        } catch (\Throwable $e) {
            throw new McpClientException('MCP request failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Decode a JSON-RPC response body (plain JSON or SSE-framed) into its
     * `result` object; JSON-RPC errors become exceptions.
     *
     * @return array<string, mixed>
     */
    private function decodeRpcResult(ResponseInterface $response): array
    {
        try {
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (\Throwable $e) {
            throw new McpClientException('MCP response could not be read: '.$e->getMessage(), 0, $e);
        }

        if ($status >= 400) {
            throw new McpClientException(sprintf('MCP server answered HTTP %d', $status));
        }
        if ('' === trim($body)) {
            // Notifications legitimately return 202/empty bodies.
            return [];
        }
        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw new McpClientException('MCP response exceeds the size limit');
        }

        $contentType = strtolower($response->getHeaders(false)['content-type'][0] ?? '');
        if (str_contains($contentType, 'text/event-stream')) {
            $body = $this->extractLastSseData($body);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new McpClientException('MCP server returned invalid JSON');
        }

        if (isset($decoded['error']) && is_array($decoded['error'])) {
            $message = is_string($decoded['error']['message'] ?? null) ? $decoded['error']['message'] : 'unknown error';
            $this->logger->info('McpClient: JSON-RPC error from server', ['error' => $decoded['error']]);
            throw new McpClientException('MCP server error: '.$message);
        }

        $result = $decoded['result'] ?? [];

        return is_array($result) ? $result : [];
    }

    /**
     * Pull the last JSON payload out of an SSE-framed response — the
     * Streamable HTTP transport delivers the JSON-RPC response as the final
     * `data:` event of the stream.
     */
    private function extractLastSseData(string $body): string
    {
        $last = '';
        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            if (str_starts_with($line, 'data:')) {
                $last = trim(substr($line, 5));
            }
        }

        if ('' === $last) {
            throw new McpClientException('MCP server returned an empty event stream');
        }

        return $last;
    }
}
