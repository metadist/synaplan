<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\AI\Service\AiFacade;
use App\AI\StructuredOutput\StructuredOutputConfig;
use App\AI\StructuredOutput\StructuredOutputSchema;
use App\Entity\User;
use App\Repository\PromptRepository;
use App\Service\FeedbackConfigService;
use App\Service\FeedbackExampleService;
use App\Service\ModelConfigService;
use App\Service\RAG\VectorSearchService;
use App\Service\RateLimitService;
use App\Service\Search\BraveSearchService;
use App\Service\UserMemoryService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class FeedbackExampleServiceStructuredOutputTest extends TestCase
{
    private AiFacade&MockObject $aiFacade;
    private UserMemoryService&MockObject $memoryService;
    private VectorSearchService&MockObject $vectorSearchService;
    private BraveSearchService&MockObject $braveSearchService;
    private FeedbackConfigService&MockObject $feedbackConfig;
    private FeedbackExampleService $service;
    private User $user;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $modelConfigService = $this->createMock(ModelConfigService::class);
        $this->memoryService = $this->createMock(UserMemoryService::class);
        $this->vectorSearchService = $this->createMock(VectorSearchService::class);
        $this->braveSearchService = $this->createMock(BraveSearchService::class);
        $promptRepository = $this->createMock(PromptRepository::class);
        $this->feedbackConfig = $this->createMock(FeedbackConfigService::class);

        $modelConfigService->method('getToolsModelConfig')
            ->willReturn(['provider' => 'test', 'model' => 'test-model', 'model_id' => 1]);
        $this->memoryService->method('isAvailable')->willReturn(false);
        $this->feedbackConfig->method('getMinContradictionScore')->willReturn(0.5);
        $this->feedbackConfig->method('getLimitPerNamespace')->willReturn(5);
        $this->feedbackConfig->method('getMinResearchScore')->willReturn(0.5);
        $this->feedbackConfig->method('getMinMemoryResearchScore')->willReturn(0.6);

        $this->service = new FeedbackExampleService(
            $this->aiFacade,
            $modelConfigService,
            $this->createMock(RateLimitService::class),
            $this->memoryService,
            $this->vectorSearchService,
            $this->braveSearchService,
            $promptRepository,
            new NullLogger(),
            $this->feedbackConfig,
            $this->alwaysOnStructuredOutputConfig(),
        );

        $this->user = $this->createMock(User::class);
        $this->user->method('getId')->willReturn(42);
    }

    private function alwaysOnStructuredOutputConfig(): StructuredOutputConfig
    {
        $config = $this->createMock(StructuredOutputConfig::class);
        $config->method('isEnabled')->willReturn(true);

        return $config;
    }

    public function testPreviewFalsePositiveForwardsTheFeedbackPreviewSchema(): void
    {
        $options = null;
        $this->aiFacade->method('chat')->willReturnCallback(
            function (array $messages, ?int $userId, array $opts) use (&$options): array {
                $options = $opts;

                return ['content' => '{"classification": "feedback", "summaryOptions": ["a"], "correctionOptions": ["b"]}'];
            }
        );

        $this->service->previewFalsePositive($this->user, 'The capital of Australia is Sydney.');

        self::assertInstanceOf(StructuredOutputSchema::class, $options['structured_output'] ?? null);
        self::assertSame('feedback_preview', $options['structured_output']->name);
    }

    public function testResearchSourcesForwardsTheSourceSummariesSchemaForKnowledgeBaseSources(): void
    {
        $this->vectorSearchService->method('semanticSearch')->willReturn([
            ['chunk_text' => 'Canberra is the capital of Australia.', 'file_name' => 'facts.pdf', 'score' => 0.9],
        ]);

        $options = null;
        $this->aiFacade->method('chat')->willReturnCallback(
            function (array $messages, ?int $userId, array $opts) use (&$options): array {
                $options = $opts;

                return ['content' => '{"summaries": ["Canberra is the capital."]}'];
            }
        );

        $result = $this->service->researchSources($this->user, 'Sydney is the capital of Australia.');

        self::assertInstanceOf(StructuredOutputSchema::class, $options['structured_output'] ?? null);
        self::assertSame('source_summaries', $options['structured_output']->name);
        self::assertCount(1, $result['sources']);
        self::assertSame('Canberra is the capital.', $result['sources'][0]['summary']);
    }

    public function testWebResearchSourcesForwardsTheSourceSummariesSchema(): void
    {
        $this->braveSearchService->method('isEnabled')->willReturn(true);
        $this->braveSearchService->method('search')->willReturn([
            'results' => [
                ['title' => 'Capital of Australia', 'url' => 'https://example.com', 'description' => 'Canberra is the capital.'],
            ],
        ]);

        $options = null;
        $this->aiFacade->method('chat')->willReturnCallback(
            function (array $messages, ?int $userId, array $opts) use (&$options): array {
                $options = $opts;

                return ['content' => '{"summaries": ["Confirms Canberra is the capital."]}'];
            }
        );

        $result = $this->service->webResearchSources($this->user, 'Sydney is the capital of Australia.');

        self::assertInstanceOf(StructuredOutputSchema::class, $options['structured_output'] ?? null);
        self::assertSame('source_summaries', $options['structured_output']->name);
        self::assertCount(1, $result['sources']);
        self::assertSame('Confirms Canberra is the capital.', $result['sources'][0]['summary']);
    }

    public function testPreviewFalsePositiveOmitsTheSchemaWhenTheKillSwitchIsOff(): void
    {
        $modelConfigService = $this->createMock(ModelConfigService::class);
        $modelConfigService->method('getToolsModelConfig')
            ->willReturn(['provider' => 'test', 'model' => 'test-model', 'model_id' => 1]);
        $promptRepository = $this->createMock(PromptRepository::class);

        $structuredOutputConfig = $this->createMock(StructuredOutputConfig::class);
        $structuredOutputConfig->method('isEnabled')->willReturn(false);

        $service = new FeedbackExampleService(
            $this->aiFacade,
            $modelConfigService,
            $this->createMock(RateLimitService::class),
            $this->memoryService,
            $this->vectorSearchService,
            $this->braveSearchService,
            $promptRepository,
            new NullLogger(),
            $this->feedbackConfig,
            $structuredOutputConfig,
        );

        $options = null;
        $this->aiFacade->method('chat')->willReturnCallback(
            function (array $messages, ?int $userId, array $opts) use (&$options): array {
                $options = $opts;

                return ['content' => '{"classification": "feedback", "summaryOptions": ["a"], "correctionOptions": ["b"]}'];
            }
        );

        $service->previewFalsePositive($this->user, 'The capital of Australia is Sydney.');

        self::assertArrayNotHasKey('structured_output', $options ?? []);
    }
}
