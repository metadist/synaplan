<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\AI\Credential\HiggsfieldCredentialResolver;
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
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
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
    private CacheItemPoolInterface&MockObject $cachePool;

    /**
     * Mimics a real Symfony cache pool (Redis in production, array in tests):
     * the first call for a given key invokes the callback and stores the
     * result; subsequent calls return the stored value without re-invoking
     * the callback. This single shared simulator covers both
     *   * the embedding shared-cache (`embed.v1.*`), and
     *   * the fallback notification throttle (`embedding_fallback_*`).
     *
     * The PSR-6 `$cachePool` mock targets the SAME storage so the
     * Contracts-based `embed()` helper and the PSR-6-based `embedBatch()`
     * helper see one consistent view of the cache — which is exactly how
     * `cache.app` (a single Redis pool exposed through both interfaces)
     * behaves in production.
     *
     * @var array<string, mixed>
     */
    private array $simulatedPersistedCache = [];

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
        $this->cachePool = $this->createMock(CacheItemPoolInterface::class);

        $this->simulatedPersistedCache = [];

        $this->cache->method('get')->willReturnCallback(
            function (string $key, callable $callback) {
                if (array_key_exists($key, $this->simulatedPersistedCache)) {
                    return $this->simulatedPersistedCache[$key];
                }

                $item = $this->createMock(ItemInterface::class);
                $item->method('expiresAfter')->willReturnSelf();

                return $this->simulatedPersistedCache[$key] = $callback($item);
            },
        );

        $this->cachePool->method('getItems')->willReturnCallback(
            function (array $keys): array {
                $items = [];
                foreach ($keys as $key) {
                    $items[$key] = $this->buildPoolItem($key);
                }

                return $items;
            },
        );

        $this->cachePool->method('getItem')->willReturnCallback(
            fn (string $key): CacheItemInterface => $this->buildPoolItem($key),
        );

        $this->cachePool->method('save')->willReturnCallback(
            function (CacheItemInterface $item): bool {
                $this->simulatedPersistedCache[$item->getKey()] = $item->get();

                return true;
            },
        );
    }

    /**
     * Build a PSR-6 item that reads from / writes to the shared simulator
     * the same way Symfony's cache pool does.
     */
    private function buildPoolItem(string $key): CacheItemInterface
    {
        $hit = array_key_exists($key, $this->simulatedPersistedCache);
        $value = $this->simulatedPersistedCache[$key] ?? null;

        $item = $this->createMock(CacheItemInterface::class);
        $item->method('getKey')->willReturn($key);
        $item->method('isHit')->willReturn($hit);
        $item->method('get')->willReturnCallback(fn () => $value);
        $item->method('set')->willReturnCallback(function ($newValue) use (&$value, $item) {
            $value = $newValue;

            return $item;
        });
        $item->method('expiresAfter')->willReturnSelf();
        $item->method('expiresAt')->willReturnSelf();

        return $item;
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

        $facade = $this->createFacade('cloudflare');
        $result = $facade->embedBatch(['text1', 'text2']);

        $this->assertCount(2, $result['embeddings']);
    }

    public function testEmbedBatchEmptyInputSkipsProvider(): void
    {
        $primary = $this->mockEmbeddingProvider('ollama');
        $primary->expects($this->never())->method('embedBatch');

        $this->registry->method('getEmbeddingProvider')->willReturn($primary);

        $facade = $this->createFacade('');
        $result = $facade->embedBatch([]);

        $this->assertSame([], $result['embeddings']);
        $this->assertSame(0, $result['usage']['total_tokens']);
    }

    public function testEmbedBatchPersistsMissesUnderEmbedV1KeyAndShortcutsLaterEmbed(): void
    {
        $primary = $this->mockEmbeddingProvider('ollama');
        $primary->expects($this->once())
            ->method('embedBatch')
            ->with(['alpha', 'beta'])
            ->willReturn([
                'embeddings' => [[0.11], [0.22]],
                'usage' => ['prompt_tokens' => 12, 'total_tokens' => 12],
            ]);
        // The follow-up embed() must be served from the SAME shared pool the
        // batch wrote into — no second provider call allowed.
        $primary->expects($this->never())->method('embed');

        $this->registry->method('getEmbeddingProvider')->willReturn($primary);

        $facade = $this->createFacade('');
        $batch = $facade->embedBatch(['alpha', 'beta']);

        $this->assertSame([[0.11], [0.22]], $batch['embeddings']);
        $this->assertSame(12, $batch['usage']['total_tokens']);

        $single = $facade->embed('beta');
        $this->assertSame([0.22], $single['embedding']);
    }

    public function testEmbedBatchPartialHitOnlySendsMissesAndKeepsOrder(): void
    {
        $primary = $this->mockEmbeddingProvider('ollama');
        // Pre-warm the cache via a single embed() so 'middle' is a hit on the
        // following batch.
        $primary->expects($this->once())
            ->method('embed')
            ->with('middle')
            ->willReturn([
                'embedding' => [0.5],
                'usage' => ['prompt_tokens' => 1, 'total_tokens' => 1],
            ]);

        // Only the two missing texts must reach the provider, in the order
        // they appeared in the original input.
        $primary->expects($this->once())
            ->method('embedBatch')
            ->with(['first', 'last'])
            ->willReturn([
                'embeddings' => [[0.1], [0.9]],
                'usage' => ['prompt_tokens' => 8, 'total_tokens' => 8],
            ]);

        $this->registry->method('getEmbeddingProvider')->willReturn($primary);

        $facade = $this->createFacade('');
        $facade->embed('middle');

        // The cache simulator persists across embed() / embedBatch(), but the
        // facade's in-process cache is per-instance — recreate to prove the
        // shared cache (Redis) actually carries the value across "requests".
        $facade = $this->createFacade('');

        $result = $facade->embedBatch(['first', 'middle', 'last']);

        $this->assertSame([[0.1], [0.5], [0.9]], $result['embeddings']);
    }

    public function testEmbedBatchFullCacheHitSkipsProviderEntirely(): void
    {
        $primary = $this->mockEmbeddingProvider('ollama');
        $primary->expects($this->exactly(2))
            ->method('embed')
            ->willReturnOnConsecutiveCalls(
                ['embedding' => [0.1], 'usage' => ['prompt_tokens' => 1, 'total_tokens' => 1]],
                ['embedding' => [0.2], 'usage' => ['prompt_tokens' => 1, 'total_tokens' => 1]],
            );
        $primary->expects($this->never())->method('embedBatch');

        $this->registry->method('getEmbeddingProvider')->willReturn($primary);

        $facade = $this->createFacade('');
        $facade->embed('a');
        $facade->embed('b');

        $facade = $this->createFacade('');
        $result = $facade->embedBatch(['a', 'b']);

        $this->assertSame([[0.1], [0.2]], $result['embeddings']);
        $this->assertSame(0, $result['usage']['total_tokens']);
    }

    public function testEmbedBatchFallbackPathIsNotPersistedUnderPrimaryKey(): void
    {
        $primary = $this->mockEmbeddingProvider('ollama');
        $primary->method('embedBatch')->willThrowException(
            new ProviderException('Down', 'ollama')
        );

        $fallback = $this->mockEmbeddingProvider('cloudflare');
        $fallback->method('embedBatch')->willReturn([
            'embeddings' => [[9.9], [8.8]],
            'usage' => ['prompt_tokens' => 0, 'total_tokens' => 0],
        ]);

        $this->registry->method('getEmbeddingProvider')
            ->willReturnCallback(fn (?string $name) => match ($name) {
                null => $primary,
                'cloudflare' => $fallback,
                default => throw new ProviderException("Unknown: $name", 'test'),
            });

        $facade = $this->createFacade('cloudflare');
        $facade->embedBatch(['x', 'y']);

        // Now bring "primary" back: it must be invoked again because the
        // fallback's vector space was intentionally NOT cached under the
        // primary's key.
        $primaryRecovered = $this->mockEmbeddingProvider('ollama');
        $primaryRecovered->expects($this->once())
            ->method('embedBatch')
            ->with(['x', 'y'])
            ->willReturn([
                'embeddings' => [[0.1], [0.2]],
                'usage' => ['prompt_tokens' => 4, 'total_tokens' => 4],
            ]);

        $this->registry = $this->createMock(ProviderRegistry::class);
        $this->registry->method('getEmbeddingProvider')->willReturn($primaryRecovered);

        $facade = $this->createFacade('cloudflare');
        $result = $facade->embedBatch(['x', 'y']);

        $this->assertSame([[0.1], [0.2]], $result['embeddings']);
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

        // The shared cache simulator in setUp() persists the throttle key
        // after the first notification, so the second `embed()` must NOT
        // re-trigger Discord/email even though it produces a fresh fallback.
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
            cachePool: $this->cachePool,
            higgsfieldCredentials: $this->createMock(HiggsfieldCredentialResolver::class),
            embeddingFallbackProvider: $fallbackProvider,
        );
    }
}
