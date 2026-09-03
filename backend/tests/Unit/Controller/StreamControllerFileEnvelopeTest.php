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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class StreamControllerFileEnvelopeTest extends TestCase
{
    private LoggerInterface&MockObject $logger;
    private StreamController $controller;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->controller = new StreamController(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(AiFacade::class),
            $this->createMock(MessageProcessor::class),
            $this->logger,
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

    public function testMalformedOfficemakerEnvelopeIsReplacedWithFailureMarker(): void
    {
        $rawEnvelope = 'Here is your file: {"BFILEPATH":"slides.pptx","BFILETEXT":"unterminated';
        $this->logger
            ->expects(self::once())
            ->method('warning')
            ->with(
                'StreamController: officemaker reply carried an unparseable file envelope; suppressing raw blob',
                ['text_length' => strlen($rawEnvelope)],
            );

        self::assertSame(
            '__FILE_GENERATION_FAILED__',
            $this->invokeSuppressor($rawEnvelope, 'officemaker', false),
        );
    }

    public function testRegularTextAndGeneratedFilesRemainUntouched(): void
    {
        $this->logger->expects(self::never())->method('warning');

        self::assertSame('Regular reply', $this->invokeSuppressor('Regular reply', 'officemaker', false));

        $rawEnvelope = '{"BFILEPATH":"slides.pptx","BFILETEXT":"content"}';
        self::assertSame($rawEnvelope, $this->invokeSuppressor($rawEnvelope, 'officemaker', true));
        self::assertSame($rawEnvelope, $this->invokeSuppressor($rawEnvelope, 'chat', false));
    }

    private function invokeSuppressor(string $text, ?string $topic, bool $hasGeneratedFile): string
    {
        $reflection = new \ReflectionMethod(StreamController::class, 'suppressUnparseableOfficemakerEnvelope');

        return (string) $reflection->invoke($this->controller, $text, $topic, $hasGeneratedFile);
    }
}
