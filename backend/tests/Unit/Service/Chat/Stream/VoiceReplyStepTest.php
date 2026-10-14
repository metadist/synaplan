<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Stream;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Service\Chat\Stream\StreamCompleteEvent;
use App\Service\Chat\Stream\StreamMessageMeta;
use App\Service\Chat\Stream\StreamUsageRecorder;
use App\Service\Chat\Stream\VoiceReplyStep;
use App\Service\Media\GeneratedFileRegistrar;
use App\Service\RateLimitService;
use App\Service\Realtime\SseWriter;
use App\Service\Usage\UsageStatsService;
use App\Service\Usage\UsageTaximeterConfig;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Voice-reply failure SSE + OUT-message meta contract (#2282).
 */
final class VoiceReplyStepTest extends TestCase
{
    private AiFacade&MockObject $aiFacade;
    private RateLimitService&MockObject $rateLimitService;
    private EntityManagerInterface&MockObject $em;
    private VoiceReplyStep $voiceReply;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->rateLimitService = $this->createMock(RateLimitService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $this->voiceReply = new VoiceReplyStep(
            $this->rateLimitService,
            $this->aiFacade,
            $this->em,
            new GeneratedFileRegistrar($this->createMock(FileRepository::class), new NullLogger(), '/tmp/upload'),
            new StreamMessageMeta(new NullLogger()),
            new StreamUsageRecorder($this->rateLimitService, $this->createStub(UsageStatsService::class), $this->createStub(UsageTaximeterConfig::class), $this->em),
            new NullLogger(),
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

    public function testBlankResponseEmitsEmptyText(): void
    {
        $user = $this->createUser(7);
        $message = $this->createPersistedMessage();

        $this->rateLimitService->method('checkLimit')->willReturn(['allowed' => true]);
        $this->aiFacade->expects(self::never())->method('synthesize');

        $events = $this->invokeProcessVoiceReply($message, $user, '');

        self::assertTrue($this->sseHas($events, 'voice_reply_failed', 'empty_text'));
        self::assertSame('empty_text', $message->getMeta('voice_reply_failed'));
    }

    public function testZeroTextStillReachesSynthesis(): void
    {
        $user = $this->createUser(7);
        $message = $this->createPersistedMessage();

        $this->rateLimitService->method('checkLimit')->willReturn(['allowed' => true]);
        $this->aiFacade->expects(self::once())->method('synthesize')->willThrowException(new \RuntimeException('no audio'));

        $events = $this->invokeProcessVoiceReply($message, $user, '0');

        self::assertTrue($this->sseHas($events, 'voice_reply_failed', 'provider_error'));
        self::assertFalse($this->sseHasStatus($events, 'audio'));
    }

    public function testUsageRecordingFailureAfterDeliveryDoesNotReportVoiceFailure(): void
    {
        $user = $this->createUser(7);
        $message = $this->createPersistedMessage();

        $this->rateLimitService->method('checkLimit')->willReturn(['allowed' => true]);
        $this->aiFacade->method('synthesize')->willReturn([
            'relativePath' => 'tts.mp3',
            'provider' => 'piper',
            'model' => 'piper-multi',
            'model_id' => 9,
            'text_length' => 12,
        ]);
        $this->rateLimitService->method('recordUsage')->willThrowException(new \RuntimeException('usage store down'));

        $events = $this->invokeProcessVoiceReply($message, $user, 'Hello world.');

        self::assertTrue($this->sseHasStatus($events, 'audio'));
        self::assertFalse($this->sseHasStatus($events, 'voice_reply_failed'));
        self::assertNull($message->getMeta('voice_reply_failed'));
        self::assertSame(1, $message->getFile());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function invokeProcessVoiceReply(Message $message, User $user, string $responseText): array
    {
        ob_start();
        try {
            $this->voiceReply->run(new SseWriter(captureMode: true), new StreamCompleteEvent(), $message, $user, $responseText, 'en', false);
        } finally {
            $raw = (string) ob_get_clean();
        }

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
