<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Usage;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\RateLimitService;
use App\Service\Usage\RecordedUsage;
use App\Service\Usage\TranscriptionUsageRecorder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class TranscriptionUsageRecorderTest extends TestCase
{
    private RateLimitService&MockObject $rateLimitService;
    private UserRepository&MockObject $userRepository;
    private TranscriptionUsageRecorder $recorder;

    protected function setUp(): void
    {
        $this->rateLimitService = $this->createMock(RateLimitService::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->recorder = new TranscriptionUsageRecorder(
            $this->rateLimitService,
            $this->userRepository,
            new NullLogger(),
        );
    }

    public function testRecordsPerSecondUsageForValidTranscription(): void
    {
        $user = $this->createMock(User::class);
        $this->userRepository->expects($this->any())->method('find')->with(42)->willReturn($user);

        $this->rateLimitService->expects($this->once())
            ->method('recordUsage')
            ->with(
                $user,
                'TRANSCRIPTION',
                $this->callback(function (array $meta): bool {
                    return 21 === $meta['model_id']
                        && 'groq' === $meta['provider']
                        && 12.5 === $meta['media_usage']['duration_seconds']
                        && 'en' === $meta['language'];
                })
            )
            ->willReturn($this->recordedUsage());

        $this->recorder->record(42, 21, 'groq', 'whisper-large-v3', 12.5, ['language' => 'en']);
    }

    public function testSkipsWhenNoUserId(): void
    {
        $this->rateLimitService->expects($this->never())->method('recordUsage');
        $this->recorder->record(null, 21, 'groq', 'whisper-large-v3', 12.5);
        $this->recorder->record(0, 21, 'groq', 'whisper-large-v3', 12.5);
    }

    public function testSkipsWhenNoModelId(): void
    {
        $this->rateLimitService->expects($this->never())->method('recordUsage');
        $this->recorder->record(42, null, 'groq', 'whisper-large-v3', 12.5);
    }

    public function testSkipsWhenDurationNotPositiveAndWarnsAboutSilentZeroBilling(): void
    {
        // A priced STT call with no duration bills $0 — that gap must be
        // visible in the logs, not silent (same undercharge class as #1314).
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('no audio duration'), $this->arrayHasKey('model_id'));

        $recorder = new TranscriptionUsageRecorder(
            $this->rateLimitService,
            $this->userRepository,
            $logger,
        );

        $this->rateLimitService->expects($this->never())->method('recordUsage');
        $recorder->record(42, 21, 'groq', 'whisper-large-v3', 0.0);
    }

    public function testSkipsWhenUserNotFound(): void
    {
        $this->userRepository->expects($this->any())->method('find')->with(42)->willReturn(null);
        $this->rateLimitService->expects($this->never())->method('recordUsage');
        $this->recorder->record(42, 21, 'groq', 'whisper-large-v3', 12.5);
    }

    public function testSwallowsRecordingErrors(): void
    {
        $user = $this->createMock(User::class);
        $this->userRepository->method('find')->willReturn($user);
        $this->rateLimitService->method('recordUsage')
            ->willThrowException(new \RuntimeException('db down'));

        // Must not throw — billing is best-effort.
        $this->recorder->record(42, 21, 'groq', 'whisper-large-v3', 12.5);
        $this->addToAssertionCount(1);
    }

    private function recordedUsage(): RecordedUsage
    {
        return new RecordedUsage(
            chargedCost: '0.000000',
            rawCost: '0.000000',
            promptTokens: 0,
            completionTokens: 0,
            totalTokens: 0,
        );
    }
}
