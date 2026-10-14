<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\AI\Service\AiFacade;
use App\Controller\StreamController;
use App\Entity\Message;
use App\Repository\FileRepository;
use App\Service\Chat\ChatTitleService;
use App\Service\Chat\ConversationSummaryRefreshDispatcher;
use App\Service\Chat\GuestSessionService;
use App\Service\Chat\Stream\StreamRequestResolver;
use App\Service\File\DocumentGeneratorService;
use App\Service\File\DocumentImageReferenceResolver;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Media\GeneratedFileRegistrar;
use App\Service\Media\MediaCancellationStore;
use App\Service\Media\MediaJobMessageSync;
use App\Service\Media\MediaJobService;
use App\Service\Memory\MemoryExtractionDispatcher;
use App\Service\Message\ChatErrorNotifier;
use App\Service\Message\ChatErrorPresenter;
use App\Service\Message\MessageForwardingService;
use App\Service\Message\MessageProcessor;
use App\Service\ModelConfigService;
use App\Service\Prompt\PromptService;
use App\Service\RateLimitService;
use App\Service\Usage\UsageStatsService;
use App\Service\Usage\UsageTaximeterConfig;
use App\Service\Widget\WidgetSessionService;
use App\Tests\Support\ChatRunServiceFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class StreamAgentIdTest extends TestCase
{
    private StreamController $controller;

    protected function setUp(): void
    {
        $this->controller = new StreamController(
            $this->createStub(StreamRequestResolver::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(AiFacade::class),
            $this->createMock(MessageProcessor::class),
            new NullLogger(),
            $this->createMock(ModelConfigService::class),
            $this->createMock(WidgetSessionService::class),
            $this->createMock(GuestSessionService::class),
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
            ChatRunServiceFactory::withoutRedis(),
            $this->createMock(ChatErrorPresenter::class),
            $this->createMock(ChatErrorNotifier::class),
        );
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

    private function invokeStamp(Message $message, ?int $agentId): void
    {
        $method = new \ReflectionMethod(StreamController::class, 'stampAgentId');
        $method->invoke($this->controller, $message, $agentId);
    }
}
