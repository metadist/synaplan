<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp;

use App\Entity\McpServerConfig;
use App\Repository\ConfigRepository;
use App\Service\EncryptionService;
use App\Service\Mcp\McpClient;
use App\Service\Mcp\McpClientConfig;
use App\Service\Mcp\McpClientException;
use App\Service\Security\SsrfGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Outbound MCP client contract (plan 09 §3.2): Streamable HTTP session
 * handshake, JSON + SSE response framing, auth header injection, SSRF guard,
 * and graceful error surfaces.
 */
final class McpClientTest extends TestCase
{
    private EncryptionService $encryption;

    protected function setUp(): void
    {
        $this->encryption = new EncryptionService('test-secret', new NullLogger());
    }

    /**
     * @param list<MockResponse>                                                      $responses
     * @param list<array{method: string, url: string, options: array<string, mixed>}> $captured
     */
    private function client(array $responses, array &$captured = []): McpClient
    {
        $factory = function (string $method, string $url, array $options) use (&$captured, &$responses): MockResponse {
            $captured[] = ['method' => $method, 'url' => $url, 'options' => $options];
            $next = array_shift($responses);

            return $next ?? new MockResponse('', ['http_code' => 202]);
        };

        $configRepo = $this->createMock(ConfigRepository::class);
        $configRepo->method('getValue')->willReturn(null);

        return new McpClient(
            new MockHttpClient($factory),
            new SsrfGuard(),
            $this->encryption,
            new McpClientConfig($configRepo),
            new NullLogger(),
        );
    }

    private function server(string $url = 'https://8.8.8.8/mcp', string $authHeader = '', string $token = ''): McpServerConfig
    {
        $server = new McpServerConfig();
        $server->setUserId(7)->setName('fixture')->setUrl($url);
        if ('' !== $authHeader) {
            $server->setAuthHeader($authHeader);
            $server->setDecryptedAuthToken($token, $this->encryption);
        }

        return $server;
    }

    private static function rpc(array $result, array $headers = []): MockResponse
    {
        return new MockResponse(
            (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]),
            ['http_code' => 200, 'response_headers' => array_merge(['content-type' => 'application/json'], $headers)],
        );
    }

    public function testListToolsRunsTheSessionHandshakeAndParsesTools(): void
    {
        $captured = [];
        $client = $this->client([
            self::rpc(['protocolVersion' => McpClient::PROTOCOL_VERSION], ['mcp-session-id' => 'sess-1']),
            new MockResponse('', ['http_code' => 202]), // initialized notification
            self::rpc(['tools' => [
                ['name' => 'search_customers', 'description' => 'Find CRM customers', 'inputSchema' => ['type' => 'object']],
                ['name' => 'broken-entry'],
            ]]),
            new MockResponse('', ['http_code' => 200]), // session DELETE
        ], $captured);

        $tools = $client->listTools($this->server());

        self::assertCount(2, $tools);
        self::assertSame('search_customers', $tools[0]['name']);
        self::assertSame('Find CRM customers', $tools[0]['description']);
        self::assertSame('broken-entry', $tools[1]['name']);

        // initialize → notification → tools/list → DELETE, session id forwarded.
        self::assertCount(4, $captured);
        self::assertSame('DELETE', $captured[3]['method']);
        $toolsListHeaders = implode("\n", $captured[2]['options']['headers'] ?? []);
        self::assertStringContainsString('Mcp-Session-Id: sess-1', $toolsListHeaders);
    }

    public function testCallToolSendsArgumentsAndReturnsContentBlocks(): void
    {
        $captured = [];
        $client = $this->client([
            self::rpc([], []),
            new MockResponse('', ['http_code' => 202]),
            self::rpc(['content' => [['type' => 'text', 'text' => 'result payload']], 'isError' => false]),
        ], $captured);

        $result = $client->callTool($this->server(), 'search_customers', ['query' => 'acme']);

        self::assertFalse($result['isError']);
        self::assertSame('result payload', $result['content'][0]['text']);

        $body = json_decode((string) $captured[2]['options']['body'], true);
        self::assertSame('tools/call', $body['method']);
        self::assertSame('search_customers', $body['params']['name']);
        self::assertSame(['query' => 'acme'], $body['params']['arguments']);
    }

    public function testAuthHeaderIsDecryptedAndSent(): void
    {
        $captured = [];
        $client = $this->client([
            self::rpc([]),
            new MockResponse('', ['http_code' => 202]),
            self::rpc(['tools' => []]),
        ], $captured);

        $client->listTools($this->server(authHeader: 'X-API-KEY', token: 'secret-key-123'));

        $initHeaders = implode("\n", $captured[0]['options']['headers'] ?? []);
        self::assertStringContainsString('X-API-KEY: secret-key-123', $initHeaders);
    }

    public function testSseFramedResponseIsParsed(): void
    {
        $sse = "event: message\ndata: "
            .json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['tools' => [['name' => 't1', 'description' => '', 'inputSchema' => []]]]])
            ."\n\n";
        $client = $this->client([
            self::rpc([]),
            new MockResponse('', ['http_code' => 202]),
            new MockResponse($sse, ['http_code' => 200, 'response_headers' => ['content-type' => 'text/event-stream']]),
        ]);

        $tools = $client->listTools($this->server());

        self::assertSame('t1', $tools[0]['name']);
    }

    public function testPrivateTargetIsBlockedBeforeAnyRequest(): void
    {
        $captured = [];
        $client = $this->client([], $captured);

        $this->expectException(McpClientException::class);
        $this->expectExceptionMessageMatches('/not allowed/');

        try {
            $client->listTools($this->server(url: 'http://127.0.0.1/mcp'));
        } finally {
            self::assertSame([], $captured, 'no HTTP request may be made to a blocked target');
        }
    }

    public function testJsonRpcErrorSurfacesAsException(): void
    {
        $client = $this->client([
            new MockResponse(
                (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32602, 'message' => 'Unknown tool']]),
                ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
            ),
        ]);

        $this->expectException(McpClientException::class);
        $this->expectExceptionMessageMatches('/Unknown tool/');

        $client->listTools($this->server());
    }

    public function testHttpErrorSurfacesAsException(): void
    {
        $client = $this->client([new MockResponse('', ['http_code' => 503])]);

        $this->expectException(McpClientException::class);
        $this->expectExceptionMessageMatches('/HTTP 503/');

        $client->listTools($this->server());
    }
}
