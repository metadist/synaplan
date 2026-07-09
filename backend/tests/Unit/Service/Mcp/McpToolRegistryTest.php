<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp;

use App\Entity\McpServerConfig;
use App\Repository\ConfigRepository;
use App\Repository\McpServerConfigRepository;
use App\Service\EncryptionService;
use App\Service\Mcp\McpClient;
use App\Service\Mcp\McpClientConfig;
use App\Service\Mcp\McpClientException;
use App\Service\Mcp\McpToolRegistry;
use App\Service\Security\SsrfGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Tool-discovery cache contract: planning must never pay a live tools/list
 * round-trip twice within the TTL, and discovery failures must degrade to an
 * empty catalog instead of breaking the turn.
 */
final class McpToolRegistryTest extends TestCase
{
    private int $httpCalls = 0;

    private function registry(callable $responseFactory, ?McpServerConfigRepository $servers = null): McpToolRegistry
    {
        $configRepo = $this->createMock(ConfigRepository::class);
        $configRepo->method('getValue')->willReturn(null);

        $client = new McpClient(
            new MockHttpClient(function (string $method, string $url, array $options) use ($responseFactory): MockResponse {
                ++$this->httpCalls;

                return $responseFactory($method, $url, $options);
            }),
            new SsrfGuard(),
            new EncryptionService('test-secret', new NullLogger()),
            new McpClientConfig($configRepo),
            new NullLogger(),
        );

        return new McpToolRegistry(
            $client,
            $servers ?? $this->createMock(McpServerConfigRepository::class),
            new ArrayAdapter(),
            new NullLogger(),
        );
    }

    private function server(): McpServerConfig
    {
        $server = new McpServerConfig();
        $server->setUserId(7)->setName('fixture')->setUrl('https://8.8.8.8/mcp');
        $ref = new \ReflectionProperty($server, 'id');
        $ref->setValue($server, 42);

        return $server;
    }

    private function happySession(): callable
    {
        return function (string $method, string $url, array $options): MockResponse {
            $body = json_decode((string) ($options['body'] ?? ''), true);
            $rpcMethod = is_array($body) ? ($body['method'] ?? '') : '';

            if ('tools/list' === $rpcMethod) {
                return new MockResponse(
                    (string) json_encode(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [
                        ['name' => 'lookup', 'description' => 'Look something up', 'inputSchema' => []],
                    ]]]),
                    ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
                );
            }

            return new MockResponse(
                (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]),
                ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
            );
        };
    }

    public function testToolsAreCachedAcrossCallsWithinTheTtl(): void
    {
        $registry = $this->registry($this->happySession());
        $server = $this->server();

        $first = $registry->toolsFor($server);
        $callsAfterFirst = $this->httpCalls;
        $second = $registry->toolsFor($server);

        self::assertSame('lookup', $first[0]['name']);
        self::assertSame($first, $second);
        self::assertSame($callsAfterFirst, $this->httpCalls, 'second lookup must be served from cache');
    }

    public function testDiscoveryFailureDegradesToEmptyList(): void
    {
        $registry = $this->registry(
            static fn (): MockResponse => new MockResponse('', ['http_code' => 500]),
        );

        self::assertSame([], $registry->toolsFor($this->server()));
    }

    public function testRefreshPropagatesTheErrorForTheSettingsUi(): void
    {
        $registry = $this->registry(
            static fn (): MockResponse => new MockResponse('', ['http_code' => 503]),
        );

        $this->expectException(McpClientException::class);
        $registry->refresh($this->server());
    }

    public function testCatalogForUserListsEnabledServersWithTheirTools(): void
    {
        $servers = $this->createMock(McpServerConfigRepository::class);
        $servers->expects(self::any())->method('findEnabledByUser')->with(7)->willReturn([$this->server()]);

        $registry = $this->registry($this->happySession(), $servers);
        $catalog = $registry->catalogForUser(7);

        self::assertCount(1, $catalog);
        self::assertSame(42, $catalog[0]['server']->getId());
        self::assertSame('lookup', $catalog[0]['tools'][0]['name']);
    }
}
