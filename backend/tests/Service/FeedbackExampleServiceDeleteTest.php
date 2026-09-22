<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\AI\Service\AiFacade;
use App\AI\StructuredOutput\StructuredOutputConfig;
use App\Entity\User;
use App\Repository\PromptRepository;
use App\Service\Exception\MemoryServiceUnavailableException;
use App\Service\FeedbackConfigService;
use App\Service\FeedbackExampleService;
use App\Service\ModelConfigService;
use App\Service\RAG\VectorSearchService;
use App\Service\RateLimitService;
use App\Service\Search\BraveSearchService;
use App\Service\UserMemoryService;
use App\Tests\Support\WebSearchGatewayFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Regression tests for {@see FeedbackExampleService::deleteFeedback}.
 *
 * A feedback row lives in one namespace. deleteFeedback used to call
 * deleteMemory once per namespace; the second call found the row already
 * gone and threw "Memory not found", which the controller reported as 404
 * after a successful delete (#2075). Outages must still propagate as
 * MemoryServiceUnavailableException rather than a fake not-found.
 */
final class FeedbackExampleServiceDeleteTest extends TestCase
{
    private AiFacade&MockObject $aiFacade;
    private ModelConfigService&MockObject $modelConfigService;
    private RateLimitService&MockObject $rateLimitService;
    private UserMemoryService&MockObject $memoryService;
    private VectorSearchService&MockObject $vectorSearchService;
    private BraveSearchService&MockObject $braveSearchService;
    private PromptRepository&MockObject $promptRepository;
    private LoggerInterface&MockObject $logger;
    private FeedbackConfigService&MockObject $feedbackConfig;

    private FeedbackExampleService $service;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->rateLimitService = $this->createMock(RateLimitService::class);
        $this->memoryService = $this->createMock(UserMemoryService::class);
        $this->vectorSearchService = $this->createMock(VectorSearchService::class);
        $this->braveSearchService = $this->createMock(BraveSearchService::class);
        $this->promptRepository = $this->createMock(PromptRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->feedbackConfig = $this->createMock(FeedbackConfigService::class);

        $structuredOutputConfig = $this->createMock(StructuredOutputConfig::class);
        $structuredOutputConfig->method('isEnabled')->willReturn(true);

        $this->service = new FeedbackExampleService(
            $this->aiFacade,
            $this->modelConfigService,
            $this->rateLimitService,
            $this->memoryService,
            $this->vectorSearchService,
            WebSearchGatewayFactory::fromBrave($this->braveSearchService),
            $this->promptRepository,
            $this->logger,
            $this->feedbackConfig,
            $structuredOutputConfig,
        );
    }

    public function testDeleteFeedbackThrowsUnavailableWhenMemoryServiceIsDown(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(42);

        $this->memoryService
            ->expects($this->once())
            ->method('isAvailable')
            ->willReturn(false);

        $this->memoryService
            ->expects($this->never())
            ->method('deleteMemory');

        $this->expectException(MemoryServiceUnavailableException::class);

        $this->service->deleteFeedback($user, 12345);
    }

    /**
     * The former namespace loop's second deleteMemory() found the row already
     * gone. A Qdrant outage on the single delete must still surface.
     */
    public function testDeleteFeedbackRethrowsUnavailable(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(42);

        $this->memoryService
            ->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);

        $this->memoryService
            ->expects($this->once())
            ->method('deleteMemory')
            ->with(12345, $user)
            ->willThrowException(new MemoryServiceUnavailableException('Qdrant down mid-request'));

        $this->expectException(MemoryServiceUnavailableException::class);

        $this->service->deleteFeedback($user, 12345);
    }

    public function testDeleteFeedbackDeletesTheRowOnce(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(42);

        $this->memoryService
            ->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);

        $this->memoryService
            ->expects($this->once())
            ->method('deleteMemory')
            ->with(12345, $user);

        $this->service->deleteFeedback($user, 12345);
    }

    public function testDeleteFeedbackPropagatesAMissingRow(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(42);

        $this->memoryService
            ->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);

        $this->memoryService
            ->expects($this->once())
            ->method('deleteMemory')
            ->with(12345, $user)
            ->willThrowException(new \InvalidArgumentException('Memory not found'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Memory not found');

        $this->service->deleteFeedback($user, 12345);
    }
}
