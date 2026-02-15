<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repository\ConfigRepository;
use App\Service\FeedbackConfigService;
use App\Service\FeedbackConstants;
use PHPUnit\Framework\TestCase;

class FeedbackConfigServiceTest extends TestCase
{
    private ConfigRepository&\PHPUnit\Framework\MockObject\Stub $configRepository;
    private FeedbackConfigService $service;

    protected function setUp(): void
    {
        $this->configRepository = $this->createStub(ConfigRepository::class);
        $this->service = new FeedbackConfigService($this->configRepository);
    }

    public function testFallsBackToDefaultWhenNoDbValue(): void
    {
        $this->configRepository->method('getValue')->willReturn(null);

        $this->assertSame(FeedbackConstants::MIN_CHAT_FEEDBACK_SCORE, $this->service->getMinChatFeedbackScore());
        $this->assertSame(FeedbackConstants::MIN_CHAT_MEMORY_SCORE, $this->service->getMinChatMemoryScore());
        $this->assertSame(FeedbackConstants::MIN_CONTRADICTION_SCORE, $this->service->getMinContradictionScore());
        $this->assertSame(FeedbackConstants::MIN_RESEARCH_SCORE, $this->service->getMinResearchScore());
        $this->assertSame(FeedbackConstants::MIN_MEMORY_RESEARCH_SCORE, $this->service->getMinMemoryResearchScore());
        $this->assertSame(FeedbackConstants::MIN_EXTRACTION_SCORE, $this->service->getMinExtractionScore());
        $this->assertSame(FeedbackConstants::LIMIT_PER_NAMESPACE, $this->service->getLimitPerNamespace());
    }

    public function testFallsBackToDefaultForEmptyString(): void
    {
        $this->configRepository->method('getValue')->willReturn('');

        $this->assertSame(FeedbackConstants::MIN_CHAT_FEEDBACK_SCORE, $this->service->getMinChatFeedbackScore());
        $this->assertSame(FeedbackConstants::LIMIT_PER_NAMESPACE, $this->service->getLimitPerNamespace());
    }

    public function testReturnsDbValueWhenSet(): void
    {
        $this->configRepository->method('getValue')
            ->willReturnCallback(function (int $ownerId, string $group, string $setting): ?string {
                return match ($setting) {
                    'MIN_CHAT_FEEDBACK_SCORE' => '0.7',
                    'LIMIT_PER_NAMESPACE' => '10',
                    'MAX_CHAT_MEMORIES' => '20',
                    default => null,
                };
            });

        $this->assertSame(0.7, $this->service->getMinChatFeedbackScore());
        $this->assertSame(10, $this->service->getLimitPerNamespace());
        $this->assertSame(20, $this->service->getMaxChatMemories());
    }

    public function testScoreOutOfRangeFallsBackToDefault(): void
    {
        $this->configRepository->method('getValue')
            ->willReturnCallback(function (int $ownerId, string $group, string $setting): ?string {
                return match ($setting) {
                    'MIN_CHAT_FEEDBACK_SCORE' => '1.5', // > 1.0
                    'MIN_CHAT_MEMORY_SCORE' => '-0.1',   // < 0.0
                    default => null,
                };
            });

        $this->assertSame(FeedbackConstants::MIN_CHAT_FEEDBACK_SCORE, $this->service->getMinChatFeedbackScore());
        $this->assertSame(FeedbackConstants::MIN_CHAT_MEMORY_SCORE, $this->service->getMinChatMemoryScore());
    }

    public function testNegativeIntFallsBackToDefault(): void
    {
        $this->configRepository->method('getValue')
            ->willReturnCallback(function (int $ownerId, string $group, string $setting): ?string {
                return match ($setting) {
                    'LIMIT_PER_NAMESPACE' => '-3',
                    'MAX_CHAT_MEMORIES' => '0',
                    default => null,
                };
            });

        $this->assertSame(FeedbackConstants::LIMIT_PER_NAMESPACE, $this->service->getLimitPerNamespace());
        $this->assertSame(10, $this->service->getMaxChatMemories()); // default is 10
    }

    public function testCachesValuesPerRequest(): void
    {
        $callCount = 0;
        $this->configRepository->method('getValue')
            ->willReturnCallback(function () use (&$callCount): string {
                ++$callCount;

                return '0.6';
            });

        // Call twice — should only hit the repository once
        $this->service->getMinChatFeedbackScore();
        $this->service->getMinChatFeedbackScore();

        $this->assertSame(1, $callCount, 'ConfigRepository should only be called once due to caching');
    }

    public function testBoundaryScoreValues(): void
    {
        $this->configRepository->method('getValue')
            ->willReturnCallback(function (int $ownerId, string $group, string $setting): ?string {
                return match ($setting) {
                    'MIN_CHAT_FEEDBACK_SCORE' => '0.0',
                    'MIN_CHAT_MEMORY_SCORE' => '1.0',
                    default => null,
                };
            });

        $this->assertSame(0.0, $this->service->getMinChatFeedbackScore());
        $this->assertSame(1.0, $this->service->getMinChatMemoryScore());
    }
}
