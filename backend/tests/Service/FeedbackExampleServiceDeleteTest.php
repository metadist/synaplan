<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Repository\PromptRepository;
use App\Service\Exception\MemoryServiceUnavailableException;
use App\Service\FeedbackConfigService;
use App\Service\FeedbackConstants;
use App\Service\FeedbackExampleService;
use App\Service\ModelConfigService;
use App\Service\RAG\VectorSearchService;
use App\Service\RateLimitService;
use App\Service\Search\BraveSearchService;
use App\Service\UserMemoryService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Regression tests for {@see FeedbackExampleService::deleteFeedback}.
 *
 * Focused on the namespace loop. Post-#809, UserMemoryService::deleteMemory
 * can only throw MemoryServiceUnavailableException (Qdrant's points/delete
 * is idempotent, so a missing point in an existing collection is a silent
 * no-op). The loop used to wrap every call in a blanket `catch (\Exception)`
 * which swallowed MemoryServiceUnavailableException and made Qdrant outages
 * surface as a misleading "Feedback not found" (400/404). These tests lock
 * in the fix: outages propagate; idempotent calls never throw.
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

        $this->service = new FeedbackExampleService(
            $this->aiFacade,
            $this->modelConfigService,
            $this->rateLimitService,
            $this->memoryService,
            $this->vectorSearchService,
            $this->braveSearchService,
            $this->promptRepository,
            $this->logger,
            $this->feedbackConfig,
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
     * The former `catch (\Exception)` inside the namespace loop swallowed
     * MemoryServiceUnavailableException (which extends \RuntimeException
     * extends \Exception). Result: both iterations failed silently and the
     * user saw "Feedback not found". This test locks in that the outage is
     * re-thrown instead.
     */
    public function testDeleteFeedbackRethrowsUnavailableFromFirstNamespace(): void
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
            ->with(12345, $user, FeedbackConstants::NAMESPACE_FALSE_POSITIVE)
            ->willThrowException(new MemoryServiceUnavailableException('Qdrant down mid-request'));

        $this->expectException(MemoryServiceUnavailableException::class);

        $this->service->deleteFeedback($user, 12345);
    }

    /**
     * Qdrant's points/delete is idempotent, so UserMemoryService::deleteMemory
     * returns normally both for "point existed and was removed" and "point
     * never existed". The loop must forward both namespaces' calls and
     * complete without error.
     */
    public function testDeleteFeedbackForwardsBothNamespacesOnSuccess(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(42);

        $this->memoryService
            ->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);

        $seen = [];
        $this->memoryService
            ->expects($this->exactly(2))
            ->method('deleteMemory')
            ->willReturnCallback(function (int $id, User $u, ?string $ns) use (&$seen): void {
                $seen[] = $ns;
            });

        $this->service->deleteFeedback($user, 12345);

        $this->assertSame(
            [FeedbackConstants::NAMESPACE_FALSE_POSITIVE, FeedbackConstants::NAMESPACE_POSITIVE],
            $seen
        );
    }

    /**
     * An outage on the *second* namespace after the first succeeded must
     * still throw 503 — never swallow a real outage.
     */
    public function testDeleteFeedbackRethrowsUnavailableFromSecondNamespace(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(42);

        $this->memoryService
            ->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);

        $call = 0;
        $this->memoryService
            ->expects($this->exactly(2))
            ->method('deleteMemory')
            ->willReturnCallback(function (int $id, User $u, ?string $ns) use (&$call): void {
                ++$call;
                if (2 === $call) {
                    throw new MemoryServiceUnavailableException('Qdrant down mid-request');
                }
            });

        $this->expectException(MemoryServiceUnavailableException::class);

        $this->service->deleteFeedback($user, 12345);
    }
}
