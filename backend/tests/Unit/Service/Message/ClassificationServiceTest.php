<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\Service\Message\ClassificationService;
use App\Service\Message\MessageSorter;
use App\Service\Message\RouterClient;
use App\UseCase\CompoundRoutingCatalog;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ClassificationServiceTest extends TestCase
{
    private RouterClient&MockObject $routerClient;
    private MessageSorter&MockObject $messageSorter;
    private CompoundRoutingCatalog $compoundCatalog;
    private LoggerInterface&MockObject $logger;
    private ClassificationService $service;

    protected function setUp(): void
    {
        $this->routerClient = $this->createMock(RouterClient::class);
        $this->messageSorter = $this->createMock(MessageSorter::class);
        $this->compoundCatalog = new CompoundRoutingCatalog();
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new ClassificationService(
            $this->routerClient,
            $this->messageSorter,
            $this->compoundCatalog,
            $this->logger,
        );
    }

    public function testFallsBackToLlmWhenRouterReturnsNull(): void
    {
        $this->routerClient->method('classify')->willReturn(null);
        $this->messageSorter->method('classify')->willReturn([
            'topic' => 'general',
            'language' => 'de',
            'web_search' => true,
        ]);

        $result = $this->service->classify(['BTEXT' => 'Hallo Welt']);

        $this->assertSame('general', $result['topic']);
        $this->assertSame('de', $result['language']);
        $this->assertSame('llm_sorter', $result['source']);
    }

    public function testFallsBackToLlmWhenBelowConfidence(): void
    {
        $this->routerClient->method('classify')->willReturn([
            'use_case' => 'image_generation',
            'confidence' => 0.5,
            'is_compound' => false,
            'steps' => [],
            'model_version' => 'v1',
            'latency_ms' => 10,
        ]);
        $this->routerClient->method('getConfidenceThreshold')->willReturn(0.80);

        $this->messageSorter->method('classify')->willReturn([
            'topic' => 'mediamaker',
            'language' => 'en',
            'web_search' => false,
        ]);

        $result = $this->service->classify(['BTEXT' => 'make an image']);

        $this->assertSame('llm_sorter', $result['source']);
    }

    public function testUsesExternalRouterWhenConfident(): void
    {
        $this->routerClient->method('classify')->willReturn([
            'use_case' => 'image_generation',
            'confidence' => 0.95,
            'is_compound' => false,
            'steps' => [],
            'model_version' => 'v2',
            'latency_ms' => 8,
        ]);
        $this->routerClient->method('getConfidenceThreshold')->willReturn(0.80);

        $result = $this->service->classify(['BTEXT' => 'generate a picture of a cat']);

        $this->assertSame('mediamaker', $result['topic']);
        $this->assertSame('external_router', $result['source']);
        $this->assertSame(0.95, $result['confidence']);
        $this->assertSame('image', $result['media_type']);
    }

    public function testDetectsCompoundFromExternalRouter(): void
    {
        $this->routerClient->method('classify')->willReturn([
            'use_case' => 'compound_research_image',
            'confidence' => 0.90,
            'is_compound' => true,
            'steps' => [
                ['capability' => 'CHAT', 'web_search' => true],
                ['capability' => 'IMAGE_GENERATION', 'media_type' => 'image'],
            ],
            'model_version' => 'v2',
            'latency_ms' => 12,
        ]);
        $this->routerClient->method('getConfidenceThreshold')->willReturn(0.80);

        $result = $this->service->classify(['BTEXT' => 'research Bitcoin and generate a chart']);

        $this->assertSame('external_router', $result['source']);
        $this->assertNotNull($result['step_plan']);
        $this->assertTrue($result['step_plan']->isCompound());
        $this->assertSame(2, $result['step_plan']->stepCount());
    }

    public function testDetectsCompoundFromCatalog(): void
    {
        $this->routerClient->method('classify')->willReturn([
            'use_case' => 'compound_write_audio',
            'confidence' => 0.88,
            'is_compound' => false,
            'steps' => [],
            'model_version' => 'v1',
            'latency_ms' => 9,
        ]);
        $this->routerClient->method('getConfidenceThreshold')->willReturn(0.80);

        $result = $this->service->classify(['BTEXT' => 'Write a poem and read it aloud']);

        $this->assertSame('external_router', $result['source']);
        $this->assertNotNull($result['step_plan']);
        $this->assertTrue($result['step_plan']->isCompound());
        $this->assertSame('catalog', $result['step_plan']->source);
    }
}
