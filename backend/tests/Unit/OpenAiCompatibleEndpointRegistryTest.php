<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\AI\Credential\OpenAiCompatibleEndpointRegistry;
use App\Entity\Config;
use App\Entity\Model;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Service\Security\EncryptionService;
use App\Service\Security\SsrfGuard;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Deterministic unit tests for the OpenAI-compatible endpoint registry.
 *
 * Uses a real {@see EncryptionService} (so the encrypt/decrypt round-trip is
 * genuinely exercised) over an in-memory fake of {@see ConfigRepository}.
 *
 * Hostnames go through a stubbed {@see SsrfGuard} (no DNS in unit tests);
 * the upstream-policy cases with literal IPs use the real guard.
 */
final class OpenAiCompatibleEndpointRegistryTest extends TestCase
{
    private const PUBLIC_IP = '203.0.113.10';

    /** @var array<string, Config> keyed by "owner|group|setting" */
    private array $store = [];

    private ConfigRepository&Stub $configRepository;
    private ModelRepository&Stub $modelRepository;
    private OpenAiCompatibleEndpointRegistry $registry;

    protected function setUp(): void
    {
        $this->store = [];

        $this->configRepository = $this->createStub(ConfigRepository::class);
        $this->configRepository->method('setValue')->willReturnCallback(
            function (int $ownerId, string $group, string $setting, string $value): Config {
                $config = (new Config())
                    ->setOwnerId($ownerId)
                    ->setGroup($group)
                    ->setSetting($setting)
                    ->setValue($value);
                $this->store[$ownerId.'|'.$group.'|'.$setting] = $config;

                return $config;
            }
        );
        $this->configRepository->method('findBy')->willReturnCallback(
            function (array $criteria): array {
                $out = [];
                foreach ($this->store as $config) {
                    if ($config->getOwnerId() === ($criteria['ownerId'] ?? null)
                        && $config->getGroup() === ($criteria['group'] ?? null)) {
                        $out[] = $config;
                    }
                }

                return $out;
            }
        );
        $this->configRepository->method('deleteValue')->willReturnCallback(
            function (int $ownerId, string $group, string $setting): bool {
                $key = $ownerId.'|'.$group.'|'.$setting;
                if (isset($this->store[$key])) {
                    unset($this->store[$key]);

                    return true;
                }

                return false;
            }
        );

        $this->modelRepository = $this->createStub(ModelRepository::class);

        $this->registry = $this->makeRegistry($this->publicHostGuard());
    }

    private function makeRegistry(SsrfGuard $guard, ?HttpClientInterface $http = null, bool $publicUpstreamsOnly = false): OpenAiCompatibleEndpointRegistry
    {
        return new OpenAiCompatibleEndpointRegistry(
            $this->configRepository,
            $this->modelRepository,
            new EncryptionService('unit-test-secret', new NullLogger()),
            new NullLogger(),
            $http ?? $this->createStub(HttpClientInterface::class),
            $guard,
            $publicUpstreamsOnly,
        );
    }

    /** A guard that resolves every hostname to one public address. */
    private function publicHostGuard(): SsrfGuard
    {
        $guard = $this->createStub(SsrfGuard::class);
        $guard->method('isBlockedUrl')->willReturn(false);
        $guard->method('pinnedIps')->willReturn([self::PUBLIC_IP]);

        return $guard;
    }

    /** An HTTP client that fails the test when a request is made. */
    private function neverCalledHttp(): HttpClientInterface
    {
        return new MockHttpClient(static function (string $method, string $url): MockResponse {
            self::fail("No request expected, got {$method} {$url}");
        });
    }

    public function testSaveAndListDoesNotLeakApiKey(): void
    {
        $this->registry->saveEndpoint('localai', 'https://localai.example.com/v1', 'sk-secret-123', ['X-Foo' => 'bar'], 'Local AI', ['chat', 'vectorize']);

        $list = $this->registry->listEndpoints();
        $this->assertCount(1, $list);
        $this->assertSame('localai', $list[0]['name']);
        $this->assertSame('Local AI', $list[0]['label']);
        $this->assertSame('https://localai.example.com/v1', $list[0]['base_url']);
        $this->assertTrue($list[0]['has_api_key']);
        $this->assertSame(['X-Foo' => 'bar'], $list[0]['headers']);
        $this->assertSame(['chat', 'vectorize'], $list[0]['capabilities']);

        // The public listing array must not contain the raw key anywhere.
        $this->assertStringNotContainsString('sk-secret-123', json_encode($list) ?: '');
    }

    public function testDefaultCapabilitiesIncludeImageGeneration(): void
    {
        $this->registry->saveEndpoint('localai', 'https://localai.example.com/v1', null);

        $endpoint = $this->registry->getEndpoint('localai');
        $this->assertNotNull($endpoint);
        $this->assertContains('text2pic', $endpoint['capabilities']);
    }

    public function testUnknownCapabilityIsDroppedAndText2PicIsKept(): void
    {
        $this->registry->saveEndpoint('localai', 'https://localai.example.com/v1', null, [], null, ['chat', 'text2pic', 'text2vid']);

        $endpoint = $this->registry->getEndpoint('localai');
        $this->assertNotNull($endpoint);
        $this->assertSame(['chat', 'text2pic'], $endpoint['capabilities']);
    }

    public function testGetEndpointReturnsDecryptedKey(): void
    {
        $this->registry->saveEndpoint('localai', 'https://localai.example.com/v1', 'sk-secret-123');

        $endpoint = $this->registry->getEndpoint('localai');
        $this->assertNotNull($endpoint);
        $this->assertSame('sk-secret-123', $endpoint['api_key']);
    }

    public function testSaveWithNullKeyPreservesExistingKey(): void
    {
        $this->registry->saveEndpoint('localai', 'https://localai.example.com/v1', 'sk-original');
        $this->registry->saveEndpoint('localai', 'https://new.example.com/v1', null);

        $endpoint = $this->registry->getEndpoint('localai');
        $this->assertNotNull($endpoint);
        $this->assertSame('https://new.example.com/v1', $endpoint['base_url']);
        $this->assertSame('sk-original', $endpoint['api_key']);
    }

    public function testSaveWithEmptyStringClearsKey(): void
    {
        $this->registry->saveEndpoint('localai', 'https://localai.example.com/v1', 'sk-original');
        $this->registry->saveEndpoint('localai', 'https://localai.example.com/v1', '');

        $endpoint = $this->registry->getEndpoint('localai');
        $this->assertNotNull($endpoint);
        $this->assertSame('', $endpoint['api_key']);
    }

    public function testInvalidNameRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry->saveEndpoint('Invalid Name!', 'https://localai.example.com/v1', null);
    }

    public function testInvalidUrlRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry->saveEndpoint('localai', 'not-a-url', null);
    }

    // --- Upstream policy (GHSA-26f3-684h-hpjh, part b) -------------------

    /** @return list<array{0: string}> */
    public static function nonHttpUrls(): array
    {
        return [
            ['ftp://gateway.example/v1'],
            ['file:///etc/passwd'],
            ['gopher://gateway.example/'],
            ['https:///no-host/v1'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonHttpUrls')]
    public function testNonHttpUpstreamIsRefusedWithoutARequest(string $url): void
    {
        $registry = $this->makeRegistry($this->publicHostGuard(), $this->neverCalledHttp());

        $result = $registry->testConnection(null, $url);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('http:// or https://', $result['error'] ?? '');

        $this->expectException(\InvalidArgumentException::class);
        $registry->saveEndpoint('gw', $url, null);
    }

    public function testProbeConnectsToThePinnedAddressAndDoesNotFollowRedirects(): void
    {
        $guard = $this->createMock(SsrfGuard::class);
        $guard->expects(self::once())->method('pinnedIps')->with('gateway.example', true)->willReturn([self::PUBLIC_IP, '203.0.113.11']);

        $seen = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = [$method, $url, $options];

            return new MockResponse(json_encode(['data' => [['id' => 'llama3'], ['id' => 'phi4']]], JSON_THROW_ON_ERROR), [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'application/json'],
            ]);
        });

        $result = $this->makeRegistry($guard, $http)->testConnection(null, 'https://gateway.example/v1/', 'sk-x');

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['model_count']);
        $this->assertSame(['llama3', 'phi4'], $result['sample']);
        $this->assertSame('GET', $seen[0]);
        $this->assertSame('https://gateway.example/v1/models', $seen[1]);
        $this->assertSame(['gateway.example' => self::PUBLIC_IP], $seen[2]['resolve']);
        $this->assertSame(0, $seen[2]['max_redirects']);
        $this->assertContains('Authorization: Bearer sk-x', $seen[2]['headers']);
    }

    public function testProbeReportsARedirectInsteadOfFollowingIt(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('', [
            'http_code' => 302,
            'response_headers' => ['location' => 'http://169.254.169.254/latest/meta-data'],
        ]));

        $result = $this->makeRegistry($this->publicHostGuard(), $http)->testConnection(null, 'https://gateway.example/v1');

        $this->assertFalse($result['ok']);
        $this->assertSame(302, $result['status']);
        $this->assertStringContainsString('redirected', $result['error'] ?? '');
    }

    public function testUnresolvableHostIsRefusedWithoutARequest(): void
    {
        $guard = $this->createStub(SsrfGuard::class);
        $guard->method('pinnedIps')->willReturn([]);
        $registry = $this->makeRegistry($guard, $this->neverCalledHttp());

        $result = $registry->testConnection(null, 'https://does-not-resolve.example/v1');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('could not be resolved', $result['error'] ?? '');

        $this->expectException(\InvalidArgumentException::class);
        $registry->saveEndpoint('gw', 'https://does-not-resolve.example/v1', null);
    }

    /**
     * Self-hosted gateways live on the operator's own network; a literal
     * private address needs no pin and is accepted by default.
     */
    public function testOperatorNetworkUpstreamIsAcceptedByDefault(): void
    {
        $seen = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = [$method, $url, $options];

            return new MockResponse(json_encode(['data' => [['id' => 'local']]], JSON_THROW_ON_ERROR), [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'application/json'],
            ]);
        });
        $registry = $this->makeRegistry(new SsrfGuard(), $http);

        $registry->saveEndpoint('lan', 'http://10.0.0.5:8080/v1', null);
        $this->assertSame('http://10.0.0.5:8080/v1', $registry->getEndpoint('lan')['base_url'] ?? null);

        $result = $registry->testConnection('lan');
        $this->assertTrue($result['ok']);
        $this->assertSame('http://10.0.0.5:8080/v1/models', $seen[1]);
        $this->assertSame([], $seen[2]['resolve'] ?? []);
    }

    /** @return list<array{0: string}> */
    public static function reservedLiteralUrls(): array
    {
        return [
            ['http://169.254.169.254/latest/meta-data'],
            ['http://[fe80::1]/v1'],
            ['http://0.0.0.0/v1'],
            ['http://192.0.2.1/v1'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reservedLiteralUrls')]
    public function testReservedAddressIsRefusedEvenForTheOperatorNetwork(string $url): void
    {
        $registry = $this->makeRegistry(new SsrfGuard(), $this->neverCalledHttp());

        $result = $registry->testConnection(null, $url);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('reserved address', $result['error'] ?? '');

        $this->expectException(\InvalidArgumentException::class);
        $registry->saveEndpoint('meta', $url, null);
    }

    public function testPublicOnlyModeRefusesTheOperatorNetwork(): void
    {
        $registry = $this->makeRegistry(new SsrfGuard(), $this->neverCalledHttp(), publicUpstreamsOnly: true);

        foreach (['http://10.0.0.5:8080/v1', 'http://127.0.0.1:11434/v1', 'http://[fd00::1]/v1'] as $url) {
            $result = $registry->testConnection(null, $url);
            $this->assertFalse($result['ok'], $url);
            $this->assertStringContainsString(OpenAiCompatibleEndpointRegistry::ENV_PUBLIC_ONLY, $result['error'] ?? '', $url);
        }

        $this->expectException(\InvalidArgumentException::class);
        $registry->saveEndpoint('lan', 'http://10.0.0.5:8080/v1', null);
    }

    public function testPublicOnlyModeStillAcceptsAPublicUpstream(): void
    {
        $guard = $this->createMock(SsrfGuard::class);
        $guard->expects(self::once())->method('isBlockedUrl')->with('https://gateway.example/v1')->willReturn(false);
        $guard->expects(self::once())->method('pinnedIps')->with('gateway.example', false)->willReturn([self::PUBLIC_IP]);

        $registry = $this->makeRegistry($guard, $this->neverCalledHttp(), publicUpstreamsOnly: true);
        $registry->saveEndpoint('gw', 'https://gateway.example/v1', null);

        $this->assertSame('https://gateway.example/v1', $registry->getEndpoint('gw')['base_url'] ?? null);
    }

    public function testResolveForModelPrefersExplicitEndpoint(): void
    {
        $this->registry->saveEndpoint('a', 'https://a.example.com/v1', null);
        $this->registry->saveEndpoint('b', 'https://b.example.com/v1', null);

        $resolved = $this->registry->resolveForModel('some-model', 'b');
        $this->assertNotNull($resolved);
        $this->assertSame('b', $resolved['name']);
    }

    public function testResolveForModelUsesModelJsonEndpoint(): void
    {
        $this->registry->saveEndpoint('a', 'https://a.example.com/v1', null);
        $this->registry->saveEndpoint('b', 'https://b.example.com/v1', null);

        $model = (new Model())
            ->setService(OpenAiCompatibleEndpointRegistry::SERVICE)
            ->setTag('chat')
            ->setProviderId('my-model')
            ->setName('My Model')
            ->setJson(['endpoint' => 'a']);

        $this->modelRepository->method('findByServiceAndProviderId')
            ->willReturn($model);

        $resolved = $this->registry->resolveForModel('my-model');
        $this->assertNotNull($resolved);
        $this->assertSame('a', $resolved['name']);
    }

    public function testResolveForModelFallsBackToSingleEndpoint(): void
    {
        $this->registry->saveEndpoint('only', 'https://only.example.com/v1', null);
        $this->modelRepository->method('findByServiceAndProviderId')->willReturn(null);

        $resolved = $this->registry->resolveForModel('unknown-model');
        $this->assertNotNull($resolved);
        $this->assertSame('only', $resolved['name']);
    }

    public function testResolveForModelReturnsNullWhenAmbiguous(): void
    {
        $this->registry->saveEndpoint('a', 'https://a.example.com/v1', null);
        $this->registry->saveEndpoint('b', 'https://b.example.com/v1', null);
        $this->modelRepository->method('findByServiceAndProviderId')->willReturn(null);

        $this->assertNull($this->registry->resolveForModel('unknown-model'));
    }

    public function testDeleteEndpoint(): void
    {
        $this->registry->saveEndpoint('localai', 'https://localai.example.com/v1', null);
        $this->assertTrue($this->registry->hasAnyEndpoint());

        $this->assertTrue($this->registry->deleteEndpoint('localai'));
        $this->assertFalse($this->registry->hasAnyEndpoint());
        $this->assertFalse($this->registry->deleteEndpoint('localai'));
    }
}
