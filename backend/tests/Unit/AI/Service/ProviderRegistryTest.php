<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Service;

use App\AI\Exception\ProviderException;
use App\AI\Interface\ChatProviderInterface;
use App\AI\Provider\TestProvider;
use App\AI\Service\ProviderRegistry;
use App\Repository\ModelRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;

class ProviderRegistryTest extends TestCase
{
    private ModelRepository&MockObject $modelRepository;
    private ?string $originalServerAppEnv;
    private ?string $originalEnvAppEnv;

    protected function setUp(): void
    {
        $this->modelRepository = $this->createMock(ModelRepository::class);
        $this->originalServerAppEnv = $_SERVER['APP_ENV'] ?? null;
        $this->originalEnvAppEnv = $_ENV['APP_ENV'] ?? null;

        $_SERVER['APP_ENV'] = 'test';
        $_ENV['APP_ENV'] = 'test';
    }

    protected function tearDown(): void
    {
        if (null === $this->originalServerAppEnv) {
            unset($_SERVER['APP_ENV']);
        } else {
            $_SERVER['APP_ENV'] = $this->originalServerAppEnv;
        }

        if (null === $this->originalEnvAppEnv) {
            unset($_ENV['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $this->originalEnvAppEnv;
        }
    }

    public function testAllowsInternalTestProviderOutsideProduction(): void
    {
        $this->modelRepository->method('getProviderCapabilities')->willReturn([]);
        $registry = $this->createRegistry();

        $provider = $registry->getChatProvider('test');

        $this->assertInstanceOf(TestProvider::class, $provider);
    }

    public function testLookupIsCaseInsensitive(): void
    {
        $this->modelRepository->method('getProviderCapabilities')->willReturn([]);
        $registry = $this->createRegistry();

        $this->assertInstanceOf(TestProvider::class, $registry->getChatProvider('Test'));
    }

    public function testRejectsInternalTestProviderInProductionWithoutDbCapability(): void
    {
        $_SERVER['APP_ENV'] = 'prod';
        $_ENV['APP_ENV'] = 'prod';

        $this->modelRepository->method('getProviderCapabilities')->willReturn([]);
        $registry = $this->createRegistry();

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage("Provider 'test' does not support capability 'chat' (not in DB)");

        $registry->getChatProvider('test');
    }

    public function testUnknownProviderListsTheRegisteredNamesWithoutInstantiatingThem(): void
    {
        $this->modelRepository->method('getProviderCapabilities')->willReturn([]);
        $built = [];
        $registry = $this->createRegistry(chat: [
            'test' => static function () use (&$built): TestProvider {
                $built[] = 'test';

                return new TestProvider();
            },
            'other' => static function () use (&$built): TestProvider {
                $built[] = 'other';

                return new TestProvider();
            },
        ]);

        try {
            $registry->getChatProvider('nope');
            $this->fail('expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertSame("chat provider 'nope' not found or unavailable. Available: test, other", $e->getMessage());
        }

        $this->assertSame([], $built, 'building the error message must not instantiate any provider');
    }

    public function testEmptyCapabilityReportsNoProvidersRegistered(): void
    {
        $registry = $this->createRegistry();

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('No providers registered for capability: file_analysis');

        $registry->getFileAnalysisProvider('test');
    }

    public function testByNameLookupInstantiatesOnlyTheRequestedProvider(): void
    {
        $this->modelRepository->method('getProviderCapabilities')->willReturn([]);
        $built = [];
        $factory = static function (string $name) use (&$built): callable {
            return static function () use ($name, &$built): TestProvider {
                $built[] = $name;

                return new TestProvider();
            };
        };
        $registry = $this->createRegistry(
            chat: ['test' => $factory('chat:test'), 'heavy' => $factory('chat:heavy')],
            embedding: ['test' => $factory('embedding:test'), 'heavy' => $factory('embedding:heavy')],
        );

        $registry->getChatProvider('test');

        $this->assertSame(['chat:test'], $built);
        $this->assertSame(['test', 'heavy'], $registry->getRegisteredProviderNames('chat'));
        $this->assertSame(['chat:test'], $built, 'listing registered names is free');
    }

    public function testListingSurfacesStillSeeEveryProvider(): void
    {
        $this->modelRepository->method('getProviderCapabilities')->willReturn([]);
        $second = $this->createStub(ChatProviderInterface::class);
        $second->method('getName')->willReturn('second');
        $second->method('isAvailable')->willReturn(false);

        $registry = $this->createRegistry(
            chat: ['test' => static fn (): TestProvider => new TestProvider(), 'second' => static fn (): ChatProviderInterface => $second],
            embedding: ['test' => static fn (): TestProvider => new TestProvider()],
        );

        $this->assertSame(['test', 'second'], array_keys($registry->getProvidersForCapability('chat')));
        $this->assertSame(['test'], $registry->getAvailableProviders('chat'), 'an unavailable provider is listed as registered but not as available');
        $this->assertSame(['test', 'second'], array_keys($registry->getUniqueProviders()));
        $this->assertCount(2, $registry->getAllProviders());
    }

    /**
     * @param array<string, callable> $chat
     * @param array<string, callable> $embedding
     */
    private function createRegistry(?array $chat = null, array $embedding = []): ProviderRegistry
    {
        $chat ??= ['test' => static fn (): TestProvider => new TestProvider()];
        $empty = new ServiceLocator([]);

        return new ProviderRegistry(
            new ServiceLocator($chat),
            new ServiceLocator($embedding),
            $empty,
            $empty,
            $empty,
            $empty,
            $empty,
            $empty,
            $this->modelRepository,
            new NullLogger(),
            'test',
            $_SERVER['APP_ENV'] ?? 'prod'
        );
    }
}
