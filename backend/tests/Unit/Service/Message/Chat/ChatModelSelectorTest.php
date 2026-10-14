<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Chat;

use App\Entity\Message;
use App\Entity\Model;
use App\Repository\ModelRepository;
use App\Service\File\ConversationFileCatalog;
use App\Service\File\GeneratedImageVisionFlag;
use App\Service\Infrastructure\PerfTimer;
use App\Service\Message\Chat\ChatModelSelector;
use App\Service\Message\Chat\ChatTurn;
use App\Service\ModelConfigService;
use App\Service\Runtime\RuntimeProfile;
use App\Service\Vision\VisionAttachmentEncoder;
use App\Service\Vision\VisionModelResolver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Which model answers a chat turn.
 *
 * A widget's `aiModelId`, a prompt's `aiModel` and the model id an older
 * message is replayed with all outrank the DEFAULTMODEL binding. They are
 * stored copies of a BID that nothing revalidates, so when Groq shut down
 * llama-3.3-70b-versatile they kept aiming at it even after the retirement
 * migration had repointed BCONFIG — every message ended in a provider
 * "model_not_found" instead of an answer.
 */
#[AllowMockObjectsWithoutExpectations]
final class ChatModelSelectorTest extends TestCase
{
    private const EFFECTIVE_USER_ID = 7;

    private ModelConfigService&MockObject $modelConfig;
    private ModelRepository&MockObject $models;
    private AbstractLogger $logger;

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logRecords = [];

    protected function setUp(): void
    {
        $this->modelConfig = $this->createMock(ModelConfigService::class);
        $this->modelConfig->method('getEffectiveUserIdForMessage')->willReturn(self::EFFECTIVE_USER_ID);
        $this->models = $this->createMock(ModelRepository::class);
        $this->logRecords = [];
        $this->logger = new class(function (string $level, string $message, array $context): void {
            $this->logRecords[] = ['level' => $level, 'message' => $message, 'context' => $context];
        }) extends AbstractLogger {
            public function __construct(private readonly \Closure $record)
            {
            }

            /**
             * @param array<string, mixed> $context
             */
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                ($this->record)((string) $level, (string) $message, $context);
            }
        };
    }

    public function testARetiredOverrideIsSwappedForTheAccountDefault(): void
    {
        $this->modelConfig->expects($this->once())
            ->method('resolveUsableModelId')
            ->with(9, 'CHAT', self::EFFECTIVE_USER_ID)
            ->willReturn(324);

        self::assertSame(324, $this->selector()->forBufferedTurn($this->turn(['model_id' => 9]))->modelId);
    }

    public function testAWorkingOverrideIsLeftAlone(): void
    {
        $this->modelConfig->method('resolveUsableModelId')->willReturn(255);

        self::assertSame(255, $this->selector()->forStreamedTurn($this->turn(['model_id' => 255]))->modelId);
        self::assertSame([], $this->warnings(), 'an unchanged model must not be logged as a fallback');
    }

    public function testTheSwapIsLoggedWithBothModelIds(): void
    {
        $this->modelConfig->method('resolveUsableModelId')->willReturn(324);

        $this->selector()->forBufferedTurn($this->turn(['model_id' => 9]));

        $warnings = $this->warnings();
        self::assertCount(1, $warnings);
        self::assertSame(9, $warnings[0]['context']['configured_model_id']);
        self::assertSame(324, $warnings[0]['context']['model_id']);
    }

    public function testNoModelStaysNoModel(): void
    {
        $this->modelConfig->method('getDefaultModel')->willReturn(null);
        $this->modelConfig->method('resolveUsableModelId')->willReturn(null);

        $choice = $this->selector()->forBufferedTurn($this->turn([]));

        self::assertNull($choice->modelId);
        self::assertNull($choice->provider);
        self::assertNull($choice->model);
    }

    /**
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $promptMetadata
     */
    #[DataProvider('modelPriority')]
    public function testTheMostSpecificModelWins(array $classification, ?RuntimeProfile $profile, array $promptMetadata, int $expected): void
    {
        $this->modelConfig->method('getDefaultModel')->willReturn(1);
        $this->modelConfig->method('resolveUsableModelId')->willReturnArgument(0);

        $turn = $this->turn($classification, $profile, $promptMetadata);

        self::assertSame($expected, $this->selector()->forBufferedTurn($turn)->modelId);
        self::assertSame($expected, $this->selector()->forStreamedTurn($turn)->modelId);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, ?RuntimeProfile, array<string, mixed>, int}>
     */
    public static function modelPriority(): iterable
    {
        $profile = self::profile(40);

        yield '"Again" replays its model' => [['model_id' => 50, 'override_model_id' => 60], $profile, ['aiModel' => 30], 50];
        yield 'the widget model beats the assistant' => [['override_model_id' => 60], $profile, ['aiModel' => 30], 60];
        yield 'the assistant beats the topic prompt' => [[], $profile, ['aiModel' => 30], 40];
        yield 'the topic prompt beats the default' => [[], null, ['aiModel' => 30], 30];
        yield 'an automated topic prompt falls back to the default' => [[], null, ['aiModel' => -1], 1];
        yield 'stored ids arrive as strings' => [[], null, ['aiModel' => '30'], 30];
    }

    public function testABufferedTurnNamesTheImageModelByItsProviderId(): void
    {
        $this->givenAnImageTheChatModelCannotSee();

        $choice = $this->selector()->forBufferedTurn($this->turn(['model_id' => 5], message: $this->messageWithImage()));

        self::assertSame(8, $choice->modelId);
        self::assertSame('openai', $choice->provider);
        self::assertSame('gpt-4o', $choice->modelName);
        self::assertTrue($choice->includeImages);
    }

    public function testAStreamedTurnResolvesTheImageModelThroughTheModelConfig(): void
    {
        $this->givenAnImageTheChatModelCannotSee();
        $this->modelConfig->method('getProviderForModel')->with(8)->willReturn('openai');
        $this->modelConfig->method('getModelName')->with(8)->willReturn('gpt-4o-2024');

        $choice = $this->selector()->forStreamedTurn($this->turn(['model_id' => 5], message: $this->messageWithImage()));

        self::assertSame(8, $choice->modelId);
        self::assertSame('openai', $choice->provider);
        self::assertSame('gpt-4o-2024', $choice->modelName);
        self::assertTrue($choice->includeImages);
    }

    public function testAnImageNoModelCanSeeLeavesTheTurnToBeRefused(): void
    {
        $this->modelConfig->method('resolveUsableModelId')->willReturnArgument(0);
        $this->modelConfig->method('getDefaultModel')->willReturn(null);
        $this->models->method('find')->willReturn($this->model(5, vision: false));
        $this->models->method('findByFeature')->willReturn(null);

        $choice = $this->selector()->forBufferedTurn($this->turn(['model_id' => 5], message: $this->messageWithImage()));

        self::assertTrue($choice->hasImages);
        self::assertFalse($choice->includeImages);
        self::assertSame(5, $choice->modelId);
    }

    private function selector(): ChatModelSelector
    {
        $flag = $this->createMock(GeneratedImageVisionFlag::class);
        $vision = new VisionAttachmentEncoder(
            sys_get_temp_dir(),
            $this->createMock(ConversationFileCatalog::class),
            $flag,
            $this->models,
            new VisionModelResolver($this->modelConfig, $this->models),
            $this->logger,
        );

        return new ChatModelSelector($this->modelConfig, $this->models, $vision, $this->logger);
    }

    /**
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $promptMetadata
     */
    private function turn(array $classification, ?RuntimeProfile $profile = null, array $promptMetadata = [], ?Message $message = null): ChatTurn
    {
        if (null === $message) {
            $message = new Message();
            $message->setUserId(self::EFFECTIVE_USER_ID);
        }

        return new ChatTurn(
            message: $message,
            thread: [],
            classification: $classification,
            options: [],
            profile: $profile,
            promptData: ['metadata' => $promptMetadata],
            perfTimer: new PerfTimer(),
        );
    }

    private function givenAnImageTheChatModelCannotSee(): void
    {
        $this->modelConfig->method('resolveUsableModelId')->willReturnArgument(0);
        $this->modelConfig->method('getDefaultModel')->willReturnCallback(
            static fn (string $capability): ?int => 'PIC2TEXT' === $capability ? 8 : null,
        );

        $chatModel = $this->model(5, vision: false);
        $imageModel = $this->model(8, vision: true);
        $this->models->method('find')->willReturnCallback(
            static fn (int $id): ?Model => [5 => $chatModel, 8 => $imageModel][$id] ?? null,
        );
    }

    private function model(int $id, bool $vision): Model
    {
        $model = $this->createMock(Model::class);
        $model->method('getId')->willReturn($id);
        $model->method('hasFeature')->willReturnCallback(static fn (string $feature): bool => $vision && 'vision' === $feature);
        $model->method('getService')->willReturn('OpenAI');
        $model->method('getProviderId')->willReturn('gpt-4o');
        $model->method('getName')->willReturn('GPT-4o');
        $model->method('getTag')->willReturn('chat');
        $model->method('getFeatures')->willReturn($vision ? ['vision'] : []);

        return $model;
    }

    private function messageWithImage(): Message
    {
        $message = new Message();
        $message->setUserId(self::EFFECTIVE_USER_ID);
        $message->setFilePath('uploads/photo.png');

        return $message;
    }

    private static function profile(int $chatModelId): RuntimeProfile
    {
        return new RuntimeProfile(
            promptId: null,
            promptTopic: '',
            systemPrompt: null,
            modelIds: ['chat' => $chatModelId],
            ragScopes: [],
            toolFlags: [],
            skillAllow: null,
            skillDeny: null,
            parameters: [],
        );
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    private function warnings(): array
    {
        return array_values(array_filter($this->logRecords, static fn (array $record): bool => 'warning' === $record['level']));
    }
}
