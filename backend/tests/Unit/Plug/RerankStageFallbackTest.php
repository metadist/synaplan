<?php

declare(strict_types=1);

namespace App\Tests\Unit\Plug;

use App\Entity\User;
use App\Plug\PlugConfigService;
use App\Plug\PlugDescriptor;
use App\Plug\PlugHealth;
use App\Plug\Rerank\RerankMetrics;
use App\Plug\Rerank\RerankOptions;
use App\Plug\Rerank\RerankProviderInterface;
use App\Plug\Rerank\RerankRegistry;
use App\Plug\Rerank\RerankResult;
use App\Plug\Rerank\RerankStage;
use App\Repository\ConfigRepository;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RerankStageFallbackTest extends TestCase
{
    public function testTimeoutKeepsEmbeddingOrderAndIncrementsMetric(): void
    {
        $metrics = new RerankMetrics(new NullLogger());
        $stage = new RerankStage(
            $this->registry(new class implements RerankProviderInterface {
                public function key(): string
                {
                    return 'http';
                }

                public function descriptor(): PlugDescriptor
                {
                    return new PlugDescriptor('http', 'HTTP', '', [], 'mixed');
                }

                public function rerank(string $query, array $candidates, int $topK, RerankOptions $options): RerankResult
                {
                    throw new \RuntimeException('timeout');
                }

                public function health(): PlugHealth
                {
                    return PlugHealth::available();
                }
            }),
            $this->config(),
            $metrics,
            $this->createMock(RateLimitService::class),
        );

        $results = [
            ['chunk_id' => '1', 'chunk_text' => 'first', 'score' => 0.9],
            ['chunk_id' => '2', 'chunk_text' => 'second', 'score' => 0.8],
        ];
        $out = $stage->apply('question', $results, 1);

        $this->assertSame('1', $out[0]['chunk_id']);
        $this->assertFalse($out[0]['rerank']['applied']);
        $this->assertSame('exception', $out[0]['rerank']['reason']);
        $this->assertSame(1, $metrics->fallbackCount('exception'));
    }

    public function testShortProviderOutputIsBackfilledInEmbeddingOrder(): void
    {
        $stage = new RerankStage(
            $this->registry(new class implements RerankProviderInterface {
                public function key(): string
                {
                    return 'http';
                }

                public function descriptor(): PlugDescriptor
                {
                    return new PlugDescriptor('http', 'HTTP', '', [], 'mixed');
                }

                public function rerank(string $query, array $candidates, int $topK, RerankOptions $options): RerankResult
                {
                    // Only the last candidate comes back; "2" and "1" are dropped.
                    return new RerankResult([['id' => '3', 'text' => 'third', 'score' => 0.99]], 'http:test', 5);
                }

                public function health(): PlugHealth
                {
                    return PlugHealth::available();
                }
            }),
            $this->config(),
            new RerankMetrics(new NullLogger()),
            $this->createMock(RateLimitService::class),
        );

        $results = [
            ['chunk_id' => '1', 'chunk_text' => 'first', 'score' => 0.9],
            ['chunk_id' => '2', 'chunk_text' => 'second', 'score' => 0.8],
            ['chunk_id' => '3', 'chunk_text' => 'third', 'score' => 0.7],
        ];
        $out = $stage->apply('question', $results, 2);

        $this->assertCount(2, $out);
        $this->assertSame(['3', '1'], array_column($out, 'chunk_id'));
        $this->assertSame(0.99, $out[0]['rerank_score']);
        $this->assertTrue($out[0]['rerank']['applied']);
        $this->assertArrayNotHasKey('backfilled', $out[0]['rerank']);
        $this->assertArrayNotHasKey('rerank_score', $out[1]);
        $this->assertTrue($out[1]['rerank']['backfilled']);
    }

    public function testStorageLimitIsKWhenDisabled(): void
    {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->method('getValue')->willReturn(null);
        $models = $this->createMock(ModelConfigService::class);
        $models->method('getDefaultModel')->willReturn(null);
        $registry = new RerankRegistry([], new PlugConfigService($repo), $models);
        $stage = new RerankStage(
            $registry,
            new PlugConfigService($repo),
            new RerankMetrics(new NullLogger()),
            $this->createMock(RateLimitService::class),
        );

        $this->assertSame(5, $stage->storageLimit(5, 'a question'));
        $this->assertSame(
            [['chunk_id' => '1', 'chunk_text' => 'x', 'score' => 1.0]],
            $stage->apply('q', [['chunk_id' => '1', 'chunk_text' => 'x', 'score' => 1.0]], 5),
        );
    }

    public function testSuccessfulHttpRerankRecordsUsage(): void
    {
        $user = $this->createMock(User::class);
        $rateLimit = $this->createMock(RateLimitService::class);
        $rateLimit->expects($this->once())->method('recordUsage')->with(
            $user,
            'RERANK',
            $this->callback(static function (array $metadata): bool {
                return 346 === ($metadata['model_id'] ?? null)
                    && 12 === ($metadata['usage']['prompt_tokens'] ?? null)
                    && 1 === ($metadata['media_usage']['requests'] ?? null)
                    && 'RAG_RERANK' === ($metadata['source'] ?? null)
                    && 'cohere' === ($metadata['provider'] ?? null)
                    && 'rerank-v3.5' === ($metadata['model'] ?? null);
            }),
        );

        $stage = new RerankStage(
            $this->registry(new class implements RerankProviderInterface {
                public function key(): string
                {
                    return 'http';
                }

                public function descriptor(): PlugDescriptor
                {
                    return new PlugDescriptor('http', 'HTTP', '', [], 'mixed');
                }

                public function rerank(string $query, array $candidates, int $topK, RerankOptions $options): RerankResult
                {
                    return new RerankResult(
                        [['id' => '1', 'text' => 'first', 'score' => 0.9]],
                        'cohere',
                        8,
                        346,
                        true,
                        12,
                        1,
                        'rerank-v3.5',
                    );
                }

                public function health(): PlugHealth
                {
                    return PlugHealth::available();
                }
            }),
            $this->config(),
            new RerankMetrics(new NullLogger()),
            $rateLimit,
        );

        $out = $stage->apply('question', [
            ['chunk_id' => '1', 'chunk_text' => 'first', 'score' => 0.9],
        ], 1, $user);

        $this->assertTrue($out[0]['rerank']['applied']);
    }

    public function testLlmRerankDoesNotRecordUsage(): void
    {
        $rateLimit = $this->createMock(RateLimitService::class);
        $rateLimit->expects($this->never())->method('recordUsage');

        $stage = new RerankStage(
            $this->registry(new class implements RerankProviderInterface {
                public function key(): string
                {
                    return 'llm';
                }

                public function descriptor(): PlugDescriptor
                {
                    return new PlugDescriptor('llm', 'LLM', '', [], 'mixed');
                }

                public function rerank(string $query, array $candidates, int $topK, RerankOptions $options): RerankResult
                {
                    return new RerankResult(
                        [['id' => '1', 'text' => 'first', 'score' => 0.9]],
                        'llm',
                        20,
                    );
                }

                public function health(): PlugHealth
                {
                    return PlugHealth::available();
                }
            }),
            $this->config(),
            new RerankMetrics(new NullLogger()),
            $rateLimit,
        );

        $stage->apply('question', [
            ['chunk_id' => '1', 'chunk_text' => 'first', 'score' => 0.9],
        ], 1, $this->createMock(User::class));
    }

    public function testBillableTimeoutStillRecordsUsage(): void
    {
        $user = $this->createMock(User::class);
        $rateLimit = $this->createMock(RateLimitService::class);
        $rateLimit->expects($this->once())->method('recordUsage');

        $stage = new RerankStage(
            $this->registry(new class implements RerankProviderInterface {
                public function key(): string
                {
                    return 'http';
                }

                public function descriptor(): PlugDescriptor
                {
                    return new PlugDescriptor('http', 'HTTP', '', [], 'mixed');
                }

                public function rerank(string $query, array $candidates, int $topK, RerankOptions $options): RerankResult
                {
                    return new RerankResult(
                        [['id' => '1', 'text' => 'first', 'score' => 0.9]],
                        'cohere',
                        50_000,
                        346,
                        true,
                        12,
                        1,
                        'rerank-v3.5',
                    );
                }

                public function health(): PlugHealth
                {
                    return PlugHealth::available();
                }
            }),
            $this->config(),
            new RerankMetrics(new NullLogger()),
            $rateLimit,
        );

        $out = $stage->apply('question', [
            ['chunk_id' => '1', 'chunk_text' => 'first', 'score' => 0.9],
        ], 1, $user);

        $this->assertFalse($out[0]['rerank']['applied']);
        $this->assertSame('timeout', $out[0]['rerank']['reason']);
    }

    public function testBillableEmptyHitsStillRecordsUsage(): void
    {
        $user = $this->createMock(User::class);
        $rateLimit = $this->createMock(RateLimitService::class);
        $rateLimit->expects($this->once())->method('recordUsage');

        $stage = new RerankStage(
            $this->registry(new class implements RerankProviderInterface {
                public function key(): string
                {
                    return 'http';
                }

                public function descriptor(): PlugDescriptor
                {
                    return new PlugDescriptor('http', 'HTTP', '', [], 'mixed');
                }

                public function rerank(string $query, array $candidates, int $topK, RerankOptions $options): RerankResult
                {
                    return new RerankResult([], 'cohere', 8, 346, true, 12, 1, 'rerank-v3.5');
                }

                public function health(): PlugHealth
                {
                    return PlugHealth::available();
                }
            }),
            $this->config(),
            new RerankMetrics(new NullLogger()),
            $rateLimit,
        );

        $out = $stage->apply('question', [
            ['chunk_id' => '1', 'chunk_text' => 'first', 'score' => 0.9],
        ], 1, $user);

        $this->assertFalse($out[0]['rerank']['applied']);
        $this->assertSame('empty', $out[0]['rerank']['reason']);
    }

    public function testBillingFailureDoesNotDiscardRankedHits(): void
    {
        $rateLimit = $this->createMock(RateLimitService::class);
        $rateLimit->method('recordUsage')->willThrowException(new \RuntimeException('buselog down'));

        $stage = new RerankStage(
            $this->registry(new class implements RerankProviderInterface {
                public function key(): string
                {
                    return 'http';
                }

                public function descriptor(): PlugDescriptor
                {
                    return new PlugDescriptor('http', 'HTTP', '', [], 'mixed');
                }

                public function rerank(string $query, array $candidates, int $topK, RerankOptions $options): RerankResult
                {
                    return new RerankResult(
                        [['id' => '1', 'text' => 'first', 'score' => 0.9]],
                        'cohere',
                        8,
                        346,
                        true,
                        12,
                        1,
                        'rerank-v3.5',
                    );
                }

                public function health(): PlugHealth
                {
                    return PlugHealth::available();
                }
            }),
            $this->config(),
            new RerankMetrics(new NullLogger()),
            $rateLimit,
        );

        $out = $stage->apply('question', [
            ['chunk_id' => '1', 'chunk_text' => 'first', 'score' => 0.9],
        ], 1, $this->createMock(User::class));

        $this->assertTrue($out[0]['rerank']['applied']);
        $this->assertSame('1', $out[0]['chunk_id']);
    }

    private function registry(RerankProviderInterface $provider): RerankRegistry
    {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->method('getValue')->willReturnCallback(
            static fn (int $o, string $g, string $s): ?string => 'RERANK.ENABLED' === $s ? '1' : null
        );
        $models = $this->createMock(ModelConfigService::class);
        $models->method('getDefaultModel')->with('RERANK')->willReturn(345);

        return new RerankRegistry([$provider], new PlugConfigService($repo), $models);
    }

    private function config(): PlugConfigService
    {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->method('getValue')->willReturn(null);

        return new PlugConfigService($repo);
    }
}
