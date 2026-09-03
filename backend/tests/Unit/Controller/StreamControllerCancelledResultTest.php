<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\AI\Service\AiFacade;
use App\Controller\StreamController;
use App\Repository\FileRepository;
use App\Service\BillingService;
use App\Service\Chat\ChatTitleService;
use App\Service\ConversationSummaryRefreshDispatcher;
use App\Service\File\DocumentGeneratorService;
use App\Service\File\DocumentImageReferenceResolver;
use App\Service\File\UserUploadPathBuilder;
use App\Service\GuestChatConfig;
use App\Service\GuestSessionService;
use App\Service\Media\GeneratedFileRegistrar;
use App\Service\Media\MediaCancellationStore;
use App\Service\Media\MediaJobMessageSync;
use App\Service\Media\MediaJobService;
use App\Service\MemoryExtractionDispatcher;
use App\Service\Message\ChatErrorNotifier;
use App\Service\Message\ChatErrorPresenter;
use App\Service\Message\MessageForwardingService;
use App\Service\Message\MessageProcessor;
use App\Service\ModelConfigService;
use App\Service\PremiumFeatureGate;
use App\Service\PromptService;
use App\Service\RateLimitService;
use App\Service\UsageStatsService;
use App\Service\UsageTaximeterConfig;
use App\Service\WidgetService;
use App\Service\WidgetSessionService;
use App\Tests\Support\ChatRunServiceFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Cancelling a multitask/media turn wrote TWO outgoing messages for one Stop
 * click: the CHAT row from /save-cancelled plus an ERROR row, because the
 * cancellation came back as a failed processing result instead of an
 * exception. The controller now recognises a cancelled result and ends the
 * turn silently.
 */
class StreamControllerCancelledResultTest extends TestCase
{
    private StreamController $controller;

    protected function setUp(): void
    {
        $this->controller = new StreamController(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(AiFacade::class),
            $this->createMock(MessageProcessor::class),
            new NullLogger(),
            $this->createMock(ModelConfigService::class),
            $this->createMock(WidgetService::class),
            $this->createMock(WidgetSessionService::class),
            $this->createMock(GuestSessionService::class),
            $this->createStub(GuestChatConfig::class),
            $this->createMock(RateLimitService::class),
            '/tmp/upload',
            $this->createMock(UserUploadPathBuilder::class),
            $this->createMock(PromptService::class),
            $this->createMock(MessageForwardingService::class),
            $this->createMock(MemoryExtractionDispatcher::class),
            $this->createMock(ConversationSummaryRefreshDispatcher::class),
            $this->createStub(ChatTitleService::class),
            $this->createMock(DocumentGeneratorService::class),
            $this->createMock(DocumentImageReferenceResolver::class),
            $this->createMock(MediaCancellationStore::class),
            $this->createMock(MediaJobService::class),
            $this->createMock(MediaJobMessageSync::class),
            new GeneratedFileRegistrar($this->createMock(FileRepository::class), new NullLogger(), '/tmp/upload'),
            $this->createMock(UsageStatsService::class),
            $this->createMock(UsageTaximeterConfig::class),
            new PremiumFeatureGate(new BillingService('', '')),
            ChatRunServiceFactory::withoutRedis(),
            $this->createMock(ChatErrorPresenter::class),
            $this->createMock(ChatErrorNotifier::class),
        );
    }

    public function testUserCancellationIsNotTreatedAsError(): void
    {
        $this->assertTrue($this->isCancelledResult([
            'success' => false,
            'cancelled' => true,
            'error' => 'Stream cancelled by user',
        ]));
    }

    public function testProviderFailureStillProducesAnErrorMessage(): void
    {
        $this->assertFalse($this->isCancelledResult([
            'success' => false,
            'error' => 'AI provider failed for streaming',
        ]));
    }

    public function testSuccessfulTurnIsNeverACancellation(): void
    {
        $this->assertFalse($this->isCancelledResult(['success' => true, 'cancelled' => true]));
    }

    /**
     * The DAG path used to report a cancel as an ordinary node failure, whose
     * message was then persisted as a second, raw-English card (#1501). The
     * marker is fixed at the source; this is the net that keeps a regression
     * from reaching the chat.
     */
    public function testDagFailureThatIsReallyACancellationIsRecognised(): void
    {
        $this->assertTrue($this->isCancelledResult([
            'success' => false,
            'error' => 'chat failed: Stream cancelled by user',
        ]));
    }

    public function testUnrelatedFailureMentioningTheUserIsStillAnError(): void
    {
        $this->assertFalse($this->isCancelledResult([
            'success' => false,
            'error' => 'chat failed: no input text for user prompt',
        ]));
    }

    /**
     * @param array<string, mixed> $result
     */
    private function isCancelledResult(array $result): bool
    {
        $reflection = new \ReflectionMethod(StreamController::class, 'isCancelledResult');

        return (bool) $reflection->invoke($this->controller, $result);
    }
}
