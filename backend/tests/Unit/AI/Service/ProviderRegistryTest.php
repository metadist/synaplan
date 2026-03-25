<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Service;

use App\AI\Exception\ProviderException;
use App\AI\Provider\TestProvider;
use App\AI\Service\ProviderRegistry;
use App\Repository\ModelRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

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

    private function createRegistry(): ProviderRegistry
    {
        return new ProviderRegistry(
            [new TestProvider()],
            [],
            [],
            [],
            [],
            [],
            [],
            [],
            $this->modelRepository,
            new NullLogger(),
            'test',
        );
    }
}
