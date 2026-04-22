<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\AI\Exception\ProviderException;
use App\AI\Interface\EmbeddingProviderInterface;
use App\AI\Service\AiFacade;
use App\AI\Service\ProviderRegistry;
use App\Service\CircuitBreaker;
use App\Service\DiscordNotificationService;
use App\Service\File\UserUploadPathBuilder;
use App\Service\InternalEmailService;
use App\Service\ModelConfigService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class AiFacadeEmbeddingFallbackTest extends TestCase
{
    private ProviderRegistry&MockObject $registry;
    private ModelConfigService&MockObject $modelConfig;
    private CircuitBreaker&MockObject $circuitBreaker;
    private LoggerInterface&MockObject $logger;
    private UserUploadPathBuilder&MockObject $pathBuilder;
    private DiscordNotificationService&MockObject $discord;
    private InternalEmailService&MockObject $emailService;
    private CacheInterface&MockObject $cache;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(ProviderRegistry::class);
        $this->modelConfig = $this->createMock(ModelConfigService::class);
        $this->circuitBreaker = $this->createMock(CircuitBreaker::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->pathBuilder = $this->createMock(UserUploadPathBuilder::class);
        $this->discord = $this->createMock(DiscordNotificationService::class);
        $this->emailService = $this->createMock(InternalEmailService::class);
        $this->cache = $this->createMock(CacheInterface::class);
    }

    public function testEmbedSucceedsWithPrimaryProvider(): void
    {
        $primary = $this->mockEmbeddingProvider('ollama');
        $primary->method('embed')->willReturn([
            'embedding' => [0.1, 0.2],
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]);

        $this->registry->method('getEmbeddingProvider')
            ->with(null)
            ->willReturn($primary);

        $facade = $this->createFacade('cloudflare');
        $result = $facade->embed('test');

        $this->assertSame([0.1, 0.2], $result['embedding']);
    }

    public function testEmbedFallsBackOnPrimaryFailure(): void
    {
        $primary = $this->mockEmbeddingProvider('ollama');
        $primary->method('embed')->willThrowException(
            new ProviderException('Connection refused', 'ollama')
        );

        $fallback = $this->mockEmbeddingProvider('cloudflare');
        $fallback->method('embed')->willReturn([
            'embedding' => [0.3, 0.4],
            'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
        ]);

        $this->registry->method('getEmbeddingProvider')
            ->willReturnCallback(fn (?string $name) => match ($name) {
                null => $primary,
                'cloudflare' => $fallback,
                default => throw new ProviderException("Unknown: $name", 'test'),
            });

        // Cache miss: callback is invoked, so we simulate that
        $this->cache->method('get')->willReturnCallback(function (string $key, callable $callback) {
            return $callback($this->createMock(ItemInterface::class));
        });

        $this->discord->expects($this->once())->method('notifyEmbeddingFallback')
            ->with('ollama', 'cloudflare', 'Connection refused');

        $this->emailService->expects($this->once())->method('sendEmbeddingFallbackWarning')
            ->with('ollama', 'cloudflare', 'Connection refused');

        $facade = $this->createFacade('cloudflare');
        $result = $facade->embed('test');

        $this->assertSame([0.3, 0.4], $result['embedding']);
    }

    public function testEmbedThrowsWhenNoFallbackConfigured(): void
    {
        $primary = $this->mockEmbeddingProvider('ollama');
        $primary->method('embed')->willThrowException(
            new ProviderException('Connection refused', 'ollama')
        );

        $this->registry->method('getEmbeddingProvider')
            ->with(null)
            ->willReturn($primary);

        $facade = $this->createFacade('');

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Connection refused');

        $facade->embed('test');
    }

    public function testEmbedThrowsWhenFallbackSameAsPrimary(): void
    {
        $primary = $this->mockEmbeddingProvider('ollama');
        $primary->method('embed')->willThrowException(
            new ProviderException('Connection refused', 'ollama')
        );

        $this->registry->method('getEmbeddingProvider')
            ->with(null)
            ->willReturn($primary);

        $facade = $this->createFacade('ollama');

        $this->expectException(ProviderException::class);
        $facade->embed('test');
    }

    public function testEmbedBatchFallsBack(): void
    {
        $primary = $this->mockEmbeddingProvider('ollama');
        $primary->method('embedBatch')->willThrowException(
            new ProviderException('Timeout', 'ollama')
        );

        $fallback = $this->mockEmbeddingProvider('cloudflare');
        $fallback->method('embedBatch')->willReturn([
            'embeddings' => [[0.1], [0.2]],
            'usage' => ['prompt_tokens' => 0, 'total_tokens' => 0],
        ]);

        $this->registry->method('getEmbeddingProvider')
            ->willReturnCallback(fn (?string $name) => match ($name) {
                null => $primary,
                'cloudflare' => $fallback,
                default => throw new ProviderException("Unknown: $name", 'test'),
            });

        $this->cache->method('get')->willReturnCallback(function (string $key, callable $callback) {
            return $callback($this->createMock(ItemInterface::class));
        });

        $facade = $this->createFacade('cloudflare');
        $result = $facade->embedBatch(['text1', 'text2']);

        $this->assertCount(2, $result['embeddings']);
    }

    public function testFallbackNotificationThrottled(): void
    {
        $primary = $this->mockEmbeddingProvider('ollama');
        $primary->method('embed')->willThrowException(
            new ProviderException('Down', 'ollama')
        );

        $fallback = $this->mockEmbeddingProvider('cloudflare');
        $fallback->method('embed')->willReturn([
            'embedding' => [0.1],
            'usage' => ['prompt_tokens' => 0, 'total_tokens' => 0],
        ]);

        $this->registry->method('getEmbeddingProvider')
            ->willReturnCallback(fn (?string $name) => match ($name) {
                null => $primary,
                'cloudflare' => $fallback,
                default => throw new ProviderException("Unknown: $name", 'test'),
            });

        $callCount = 0;
        $this->cache->method('get')->willReturnCallback(
            function (string $key, callable $callback) use (&$callCount) {
                ++$callCount;
                if ($callCount <= 1) {
                    return $callback($this->createMock(ItemInterface::class));
                }

                return true;
            }
        );

        $this->discord->expects($this->once())->method('notifyEmbeddingFallback');
        $this->emailService->expects($this->once())->method('sendEmbeddingFallbackWarning');

        $facade = $this->createFacade('cloudflare');
        $facade->embed('test1');
        $facade->embed('test2');
    }

    public function testFallbackProviderFailureStillThrowsPrimary(): void
    {
        $primary = $this->mockEmbeddingProvider('ollama');
        $primary->method('embed')->willThrowException(
            new ProviderException('Primary down', 'ollama')
        );

        $this->registry->method('getEmbeddingProvider')
            ->willReturnCallback(function (?string $name) use ($primary) {
                if (null === $name) {
                    return $primary;
                }
                throw new ProviderException('Fallback unavailable', 'cloudflare');
            });

        $facade = $this->createFacade('cloudflare');

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Primary down');

        $facade->embed('test');
    }

    private function mockEmbeddingProvider(string $name): EmbeddingProviderInterface&MockObject
    {
        $provider = $this->createMock(EmbeddingProviderInterface::class);
        $provider->method('getName')->willReturn($name);
        $provider->method('getDefaultModels')->willReturn(['embedding' => 'default-model']);

        return $provider;
    }

    private function createFacade(string $fallbackProvider): AiFacade
    {
        return new AiFacade(
            registry: $this->registry,
            modelConfig: $this->modelConfig,
            circuitBreaker: $this->circuitBreaker,
            logger: $this->logger,
            userUploadPathBuilder: $this->pathBuilder,
            discordNotification: $this->discord,
            emailService: $this->emailService,
            cache: $this->cache,
            embeddingFallbackProvider: $fallbackProvider,
        );
    }
}
