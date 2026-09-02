<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\AI\Service\AiFacade;
use App\AI\StructuredOutput\StructuredOutputConfig;
use App\AI\StructuredOutput\StructuredOutputSchema;
use App\Entity\User;
use App\Repository\PromptRepository;
use App\Service\FeedbackConfigService;
use App\Service\FeedbackContradictionService;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use App\Service\UserMemoryService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class FeedbackContradictionServiceStructuredOutputTest extends TestCase
{
    private AiFacade&MockObject $aiFacade;
    private UserMemoryService&MockObject $memoryService;
    private FeedbackConfigService&MockObject $feedbackConfig;
    private FeedbackContradictionService $service;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $modelConfigService = $this->createMock(ModelConfigService::class);
        $this->memoryService = $this->createMock(UserMemoryService::class);
        $promptRepository = $this->createMock(PromptRepository::class);
        $this->feedbackConfig = $this->createMock(FeedbackConfigService::class);

        $modelConfigService->method('getToolsModelConfig')
            ->willReturn(['provider' => 'test', 'model' => 'test-model', 'model_id' => 1]);
        $promptRepository->method('findOneBy')->willReturn(null);
        $this->feedbackConfig->method('getLimitPerNamespace')->willReturn(5);
        $this->feedbackConfig->method('getMinContradictionScore')->willReturn(0.5);

        $this->service = new FeedbackContradictionService(
            $this->aiFacade,
            $modelConfigService,
            $this->createMock(RateLimitService::class),
            $this->memoryService,
            $promptRepository,
            new NullLogger(),
            $this->feedbackConfig,
            $this->alwaysOnStructuredOutputConfig(),
        );
    }

    private function alwaysOnStructuredOutputConfig(): StructuredOutputConfig
    {
        $config = $this->createMock(StructuredOutputConfig::class);
        $config->method('isEnabled')->willReturn(true);

        return $config;
    }

    private function stubOneRelatedMemory(): void
    {
        $this->memoryService->method('isAvailable')->willReturn(true);
        $this->memoryService->method('searchRelevantMemories')->willReturnCallback(
            static fn (int $userId, string $query, ?string $category = null): array => null === $category
                ? [['id' => 1, 'key' => 'age', 'value' => '30', 'score' => 0.9]]
                : []
        );
    }

    public function testCheckContradictionsForwardsTheFeedbackContradictionSchema(): void
    {
        $this->stubOneRelatedMemory();

        $options = null;
        $this->aiFacade->method('chat')->willReturnCallback(
            function (array $messages, ?int $userId, array $opts) use (&$options): array {
                $options = $opts;

                return ['content' => '{"contradictions": []}'];
            }
        );

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(42);

        $this->service->checkContradictions($user, 'I am 25 years old', 'positive');

        self::assertInstanceOf(StructuredOutputSchema::class, $options['structured_output'] ?? null);
        self::assertSame('feedback_contradiction', $options['structured_output']->name);
    }

    public function testCheckContradictionsBatchForwardsTheFeedbackContradictionSchema(): void
    {
        $this->stubOneRelatedMemory();

        $options = null;
        $this->aiFacade->method('chat')->willReturnCallback(
            function (array $messages, ?int $userId, array $opts) use (&$options): array {
                $options = $opts;

                return ['content' => '{"contradictions": []}'];
            }
        );

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(42);

        $this->service->checkContradictionsBatch($user, 'I am 25 years old', 'I am 30 years old');

        self::assertInstanceOf(StructuredOutputSchema::class, $options['structured_output'] ?? null);
        self::assertSame('feedback_contradiction', $options['structured_output']->name);
    }

    public function testCheckContradictionsOmitsTheSchemaWhenTheKillSwitchIsOff(): void
    {
        $this->stubOneRelatedMemory();

        $options = null;
        $this->aiFacade->method('chat')->willReturnCallback(
            function (array $messages, ?int $userId, array $opts) use (&$options): array {
                $options = $opts;

                return ['content' => '{"contradictions": []}'];
            }
        );

        $modelConfigService = $this->createMock(ModelConfigService::class);
        $modelConfigService->method('getToolsModelConfig')
            ->willReturn(['provider' => 'test', 'model' => 'test-model', 'model_id' => 1]);
        $promptRepository = $this->createMock(PromptRepository::class);
        $promptRepository->method('findOneBy')->willReturn(null);

        $structuredOutputConfig = $this->createMock(StructuredOutputConfig::class);
        $structuredOutputConfig->method('isEnabled')->willReturn(false);

        $service = new FeedbackContradictionService(
            $this->aiFacade,
            $modelConfigService,
            $this->createMock(RateLimitService::class),
            $this->memoryService,
            $promptRepository,
            new NullLogger(),
            $this->feedbackConfig,
            $structuredOutputConfig,
        );

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(42);

        $service->checkContradictions($user, 'I am 25 years old', 'positive');

        self::assertArrayNotHasKey('structured_output', $options ?? []);
    }

    /**
     * The object-rooted schema response (`{"contradictions": [...]}`) already
     * matches the format the parser expects — no parsing change was needed.
     */
    public function testWrappedSchemaResponseParsesTheContradictionList(): void
    {
        $this->stubOneRelatedMemory();

        $this->aiFacade->method('chat')->willReturn([
            'content' => '{"contradictions": [{"id": 1, "type": "memory", "value": "30", "reason": "age mismatch"}]}',
        ]);

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(42);

        $result = $this->service->checkContradictions($user, 'I am 25 years old', 'positive');

        self::assertTrue($result['hasContradictions']);
        self::assertSame(1, $result['contradictions'][0]['id']);
        self::assertSame('memory', $result['contradictions'][0]['type']);
    }
}
