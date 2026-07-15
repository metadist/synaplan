<?php

namespace App\Tests\Unit\Service\Message\Handler;

use App\AI\Service\AiFacade;
use App\Entity\Model;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Repository\PromptRepository;
use App\Service\FeedbackConfigService;
use App\Service\File\DocumentGeneratorService;
use App\Service\File\UserUploadPathBuilder;
use App\Service\MemoryExtractionDispatcher;
use App\Service\Message\Handler\ChatHandler;
use App\Service\ModelConfigService;
use App\Service\PerfPipelineFlag;
use App\Service\Prompt\TimeContextBuilder;
use App\Service\PromptService;
use App\Service\RAG\VectorSearchService;
use App\Service\RateLimitService;
use App\Service\UserMemoryService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * When the account's chat model can't see images, the vision fallback must
 * honour the CONFIGURED image model (DEFAULTMODEL.PIC2TEXT) instead of grabbing
 * the globally highest-quality vision model. Previously the global pick meant a
 * WhatsApp image reply could arrive as an Anthropic answer even though the
 * account never configured that provider.
 */
class ChatHandlerVisionModelResolutionTest extends TestCase
{
    public function testPrefersConfiguredPic2TextModel(): void
    {
        $configured = $this->createMock(Model::class);
        $configured->method('getId')->willReturn(42);
        $configured->method('hasFeature')->willReturn(true);

        $modelConfig = $this->createMock(ModelConfigService::class);
        $modelConfig->method('getDefaultModel')->willReturn(42);

        $repo = $this->createMock(ModelRepository::class);
        $repo->method('find')->willReturn($configured);
        // The global catalog pick must NOT be consulted when a configured
        // vision model is available.
        $repo->expects($this->never())->method('findByFeature');

        $result = $this->invokeResolve($this->makeHandler($modelConfig, $repo), 7);

        $this->assertSame($configured, $result);
    }

    public function testFallsBackToCatalogWhenNoConfiguredModel(): void
    {
        $catalog = $this->createMock(Model::class);

        $modelConfig = $this->createMock(ModelConfigService::class);
        $modelConfig->method('getDefaultModel')->willReturn(null);

        $repo = $this->createMock(ModelRepository::class);
        $repo->method('findByFeature')->willReturn($catalog);

        $result = $this->invokeResolve($this->makeHandler($modelConfig, $repo), 7);

        $this->assertSame($catalog, $result);
    }

    public function testFallsBackToCatalogWhenConfiguredModelLacksVision(): void
    {
        $configured = $this->createMock(Model::class);
        $configured->method('hasFeature')->willReturn(false);
        $catalog = $this->createMock(Model::class);

        $modelConfig = $this->createMock(ModelConfigService::class);
        $modelConfig->method('getDefaultModel')->willReturn(99);

        $repo = $this->createMock(ModelRepository::class);
        $repo->method('find')->willReturn($configured);
        $repo->method('findByFeature')->willReturn($catalog);

        $result = $this->invokeResolve($this->makeHandler($modelConfig, $repo), 7);

        $this->assertSame($catalog, $result);
    }

    private function invokeResolve(ChatHandler $handler, ?int $userId): ?Model
    {
        $method = new \ReflectionMethod(ChatHandler::class, 'resolveVisionFallbackModel');
        $method->setAccessible(true);

        $result = $method->invoke($handler, $userId);

        return $result instanceof Model ? $result : null;
    }

    private function makeHandler(ModelConfigService $modelConfig, ModelRepository $repo): ChatHandler
    {
        return new ChatHandler(
            $this->createMock(AiFacade::class),
            $this->createMock(PromptRepository::class),
            $this->createMock(PromptService::class),
            $modelConfig,
            $repo,
            new NullLogger(),
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
            new TimeContextBuilder(),
        );
    }
}
