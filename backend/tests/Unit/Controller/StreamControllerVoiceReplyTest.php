<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\AI\Service\AiFacade;
use App\Controller\StreamController;
use App\Entity\Message;
use App\Entity\User;
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
use Psr\Log\NullLogger;

/**
 * Voice-reply failure SSE + OUT-message meta contract (#2282).
 */
final class StreamControllerVoiceReplyTest extends TestCase
{
    private AiFacade&MockObject $aiFacade;
    private RateLimitService&MockObject $rateLimitService;
    private EntityManagerInterface&MockObject $em;
    private StreamController $controller;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->rateLimitService = $this->createMock(RateLimitService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $this->controller = new StreamController(
            $this->em,
            $this->aiFacade,
            $this->createMock(MessageProcessor::class),
            new NullLogger(),
            $this->createMock(ModelConfigService::class),
            $this->createMock(WidgetService::class),
            $this->createMock(WidgetSessionService::class),
            $this->createMock(GuestSessionService::class),
            $this->createStub(GuestChatConfig::class),
            $this->rateLimitService,
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
            $this->createMock(\App\Service\Agent\AgentConfig::class),
            $this->createMock(\App\Service\Agent\AgentService::class),
            $this->createMock(\App\Service\Agent\AgentRuntimeResolver::class),
        );
    }

    public function testProviderErrorEmitsVoiceReplyFailedAndStoresMeta(): void
    {
        $user = $this->createUser(7);
        $message = $this->createPersistedMessage();

        $this->rateLimitService->method('checkLimit')->willReturn(['allowed' => true]);
        $this->aiFacade->method('synthesize')->willThrowException(new \RuntimeException('No audio data in response'));
        $this->em->expects(self::atLeastOnce())->method('flush');

        $events = $this->invokeProcessVoiceReply($message, $user, 'Hello world.');

        self::assertTrue($this->sseHas($events, 'voice_reply_failed', 'provider_error'));
        self::assertSame('provider_error', $message->getMeta('voice_reply_failed'));
        self::assertFalse($this->sseHasStatus($events, 'audio'));
    }

    public function testRateLimitedEmitsVoiceReplyFailedAndStoresMeta(): void
    {
        $user = $this->createUser(7);
        $message = $this->createPersistedMessage();

        $this->rateLimitService->method('checkLimit')->willReturn(['allowed' => false]);
        $this->aiFacade->expects(self::never())->method('synthesize');
        $this->em->expects(self::atLeastOnce())->method('flush');

        $events = $this->invokeProcessVoiceReply($message, $user, 'Hello world.');

        self::assertTrue($this->sseHas($events, 'voice_reply_failed', 'rate_limited'));
        self::assertSame('rate_limited', $message->getMeta('voice_reply_failed'));
        self::assertFalse($this->sseHasStatus($events, 'tts_generating'));
        self::assertFalse($this->sseHasStatus($events, 'audio'));
    }

    public function testEmptySpeakableTextEmitsVoiceReplyFailedAndStoresMeta(): void
    {
        $user = $this->createUser(7);
        $message = $this->createPersistedMessage();

        $this->rateLimitService->method('checkLimit')->willReturn(['allowed' => true]);
        $this->aiFacade->expects(self::never())->method('synthesize');
        $this->em->expects(self::atLeastOnce())->method('flush');

        // Markdown / memory badges / code-only content can sanitize to empty.
        $events = $this->invokeProcessVoiceReply($message, $user, "```\n```\n[Memory:1]");

        self::assertTrue($this->sseHas($events, 'voice_reply_failed', 'empty_text'));
        self::assertSame('empty_text', $message->getMeta('voice_reply_failed'));
        self::assertTrue($this->sseHasStatus($events, 'tts_generating'));
        self::assertFalse($this->sseHasStatus($events, 'audio'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function invokeProcessVoiceReply(Message $message, User $user, string $responseText): array
    {
        $completeData = [];
        $usageExtra = [];

        $capture = new \ReflectionProperty(StreamController::class, 'sseCaptureMode');
        $capture->setValue($this->controller, true);

        ob_start();
        $reflection = new \ReflectionMethod(StreamController::class, 'processVoiceReply');
        $reflection->invokeArgs($this->controller, [
            $message,
            $user,
            $responseText,
            'en',
            false,
            &$completeData,
            &$usageExtra,
        ]);
        $raw = (string) ob_get_clean();

        $events = [];
        foreach (preg_split("/\n\n+/", trim($raw)) ?: [] as $frame) {
            $frame = trim($frame);
            if (!str_starts_with($frame, 'data: ')) {
                continue;
            }
            $decoded = json_decode(substr($frame, 6), true);
            if (\is_array($decoded)) {
                $events[] = $decoded;
            }
        }

        return $events;
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function sseHas(array $events, string $status, string $reason): bool
    {
        foreach ($events as $event) {
            if (($event['status'] ?? null) === $status && ($event['reason'] ?? null) === $reason) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function sseHasStatus(array $events, string $status): bool
    {
        foreach ($events as $event) {
            if (($event['status'] ?? null) === $status) {
                return true;
            }
        }

        return false;
    }

    private function createPersistedMessage(): Message
    {
        $message = new Message();
        $reflection = new \ReflectionProperty(Message::class, 'id');
        $reflection->setValue($message, 42);

        return $message;
    }

    private function createUser(int $id): User
    {
        $user = new User();
        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, $id);

        return $user;
    }
}
