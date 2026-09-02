<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Handler;

use App\AI\Service\AiFacade;
use App\AI\StructuredOutput\StructuredOutputConfig;
use App\Entity\Message;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Repository\PromptRepository;
use App\Service\FeedbackConfigService;
use App\Service\File\DocumentGeneratorService;
use App\Service\File\DocumentImageCatalog;
use App\Service\File\DocumentImageReferenceResolver;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Knowledge\KnowledgeContextFormatter;
use App\Service\MemoryExtractionDispatcher;
use App\Service\Message\Handler\ChatHandler;
use App\Service\ModelConfigService;
use App\Service\PerfPipelineFlag;
use App\Service\Prompt\TimeContextBuilder;
use App\Service\PromptService;
use App\Service\RAG\VectorSearchService;
use App\Service\RateLimitService;
use App\Service\UserMemoryService;
use App\Service\Vision\VisionModelResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * A widget's `aiModelId`, a prompt's `aiModel` and the model id an older
 * message is replayed with all outrank the DEFAULTMODEL binding. They are
 * stored copies of a BID that nothing revalidates, so when Groq shut down
 * llama-3.3-70b-versatile they kept aiming at it even after the retirement
 * migration had repointed BCONFIG — every message ended in a provider
 * "model_not_found" instead of an answer.
 */
final class ChatHandlerStaleModelFallbackTest extends TestCase
{
    public function testARetiredOverrideIsSwappedForTheAccountDefault(): void
    {
        $modelConfig = $this->createMock(ModelConfigService::class);
        $modelConfig->expects($this->once())
            ->method('resolveUsableModelId')
            ->with(9, 'CHAT', 7)
            ->willReturn(324);

        self::assertSame(324, $this->degrade($modelConfig, 9, 7));
    }

    public function testAWorkingOverrideIsLeftAlone(): void
    {
        $modelConfig = $this->createMock(ModelConfigService::class);
        $modelConfig->method('resolveUsableModelId')->willReturn(255);

        $logger = $this->recordingLogger();

        self::assertSame(255, $this->degrade($modelConfig, 255, 7, $logger));
        self::assertSame([], $logger->records, 'an unchanged model must not be logged as a fallback');
    }

    public function testTheSwapIsLoggedWithBothModelIds(): void
    {
        $modelConfig = $this->createMock(ModelConfigService::class);
        $modelConfig->method('resolveUsableModelId')->willReturn(324);

        $logger = $this->recordingLogger();
        $this->degrade($modelConfig, 9, 7, $logger);

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame(9, $logger->records[0]['context']['configured_model_id']);
        self::assertSame(324, $logger->records[0]['context']['model_id']);
    }

    public function testNoModelStaysNoModel(): void
    {
        $modelConfig = $this->createMock(ModelConfigService::class);
        $modelConfig->method('resolveUsableModelId')->willReturn(null);

        self::assertNull($this->degrade($modelConfig, null, 7));
    }

    private function degrade(
        ModelConfigService $modelConfig,
        ?int $modelId,
        ?int $effectiveUserId,
        ?LoggerInterface $logger = null,
    ): ?int {
        $handler = $this->makeHandler($modelConfig, $logger ?? $this->recordingLogger());

        $method = new \ReflectionMethod(ChatHandler::class, 'degradeToUsableModel');
        $method->setAccessible(true);

        $message = new Message();
        $message->setUserId($effectiveUserId ?? 0);

        /** @var int|null $result */
        $result = $method->invoke($handler, $modelId, $effectiveUserId, $message);

        return $result;
    }

    /**
     * @return AbstractLogger&object{records: list<array{level: string, context: array<string, mixed>}>}
     */
    private function recordingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: string, context: array<string, mixed>}> */
            public array $records = [];

            /**
             * @param array<string, mixed> $context
             */
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'context' => $context];
            }
        };
    }

    private function makeHandler(ModelConfigService $modelConfig, LoggerInterface $logger): ChatHandler
    {
        $repo = $this->createMock(ModelRepository::class);

        return new ChatHandler(
            $this->createMock(AiFacade::class),
            $this->createMock(PromptRepository::class),
            $this->createMock(PromptService::class),
            $modelConfig,
            $repo,
            $logger,
            $this->createMock(VectorSearchService::class),
            $this->createMock(EntityManagerInterface::class),
            sys_get_temp_dir(),
            new UserUploadPathBuilder(),
            $this->createMock(UserMemoryService::class),
            new FeedbackConfigService($this->createStub(ConfigRepository::class)),
            $this->createMock(RateLimitService::class),
            $this->createMock(MemoryExtractionDispatcher::class),
            $this->createMock(PerfPipelineFlag::class),
            $this->createMock(DocumentGeneratorService::class),
            $this->createMock(DocumentImageReferenceResolver::class),
            $this->createMock(DocumentImageCatalog::class),
            new TimeContextBuilder(),
            new KnowledgeContextFormatter(),
            new VisionModelResolver($modelConfig, $repo),
            $this->createMock(\App\Service\Digest\DigestSearchService::class),
            $this->createMock(\App\Service\Digest\MessageDigestConfig::class),
            $this->createMock(\App\Service\File\ConversationFileCatalog::class),
            $this->createMock(\App\Service\File\GeneratedImageVisionFlag::class),
            $this->createMock(StructuredOutputConfig::class),
        );
    }
}
