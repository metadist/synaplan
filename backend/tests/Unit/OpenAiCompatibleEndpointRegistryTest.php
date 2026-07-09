<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\AI\Credential\OpenAiCompatibleEndpointRegistry;
use App\Entity\Config;
use App\Entity\Model;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Service\EncryptionService;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Deterministic unit tests for the OpenAI-compatible endpoint registry.
 *
 * Uses a real {@see EncryptionService} (so the encrypt/decrypt round-trip is
 * genuinely exercised) over an in-memory fake of {@see ConfigRepository}.
 */
final class OpenAiCompatibleEndpointRegistryTest extends TestCase
{
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

        $this->registry = new OpenAiCompatibleEndpointRegistry(
            $this->configRepository,
            $this->modelRepository,
            new EncryptionService('unit-test-secret', new NullLogger()),
            new NullLogger(),
            $this->createStub(HttpClientInterface::class),
        );
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
