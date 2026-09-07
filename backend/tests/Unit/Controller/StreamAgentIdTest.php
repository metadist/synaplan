<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\AI\Service\AiFacade;
use App\Controller\StreamController;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Service\Agent\AgentConfig;
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class StreamAgentIdTest extends TestCase
{
    private AgentConfig&MockObject $agentConfig;
    private StreamController $controller;

    protected function setUp(): void
    {
        $this->agentConfig = $this->createMock(AgentConfig::class);
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
            agentConfig: $this->agentConfig,
        );
    }

    public function testFlagOffIgnoresAgentId(): void
    {
        $this->agentConfig->method('isEnabled')->willReturn(false);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(4);

        self::assertNull($this->invokeResolve($user, 7));
    }

    public function testFlagOnPinsAgentId(): void
    {
        $this->agentConfig->expects(self::once())->method('isEnabled')->with(4)->willReturn(true);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(4);

        self::assertSame(7, $this->invokeResolve($user, 7));
    }

    public function testStampWritesAgentIdOnIncomingAndOutgoing(): void
    {
        $incoming = $this->messageWithId(11);
        $outgoing = $this->messageWithId(12);
        $this->invokeStamp($incoming, 7);
        $this->invokeStamp($outgoing, 7);

        self::assertSame('7', $incoming->getMeta('AGENTID'));
        self::assertSame('7', $outgoing->getMeta('AGENTID'));
    }

    public function testStampIsNoOpWhenAgentIdMissing(): void
    {
        $message = $this->messageWithId(13);
        $this->invokeStamp($message, null);

        self::assertNull($message->getMeta('AGENTID'));
    }

    /**
     * MessageMeta::setMessage() copies a non-nullable int id, so a transient
     * Message needs one before setMeta() works.
     */
    private function messageWithId(int $id): Message
    {
        $message = new Message();
        (new \ReflectionProperty(Message::class, 'id'))->setValue($message, $id);

        return $message;
    }

    private function invokeResolve(User $user, ?int $agentId): ?int
    {
        $method = new \ReflectionMethod(StreamController::class, 'resolvePinnedAgentId');

        return $method->invoke($this->controller, $user, $agentId);
    }

    private function invokeStamp(Message $message, ?int $agentId): void
    {
        $method = new \ReflectionMethod(StreamController::class, 'stampAgentId');
        $method->invoke($this->controller, $message, $agentId);
    }
}
