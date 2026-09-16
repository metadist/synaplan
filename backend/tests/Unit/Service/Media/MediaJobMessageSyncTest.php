<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\Entity\File;
use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Service\File\ThumbnailService;
use App\Service\Media\GeneratedFileRegistrar;
use App\Service\Media\MediaJob;
use App\Service\Media\MediaJobMessageSync;
use App\Service\Media\MediaJobRealtimeNotifier;
use App\Service\Media\MediaJobService;
use App\Service\Media\MediaJobUsageRecorder;
use App\Service\Multitask\TaskPlanStore;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * #1251: the ASYNC (detached-job) media path must persist the spoken TTS script
 * as BFILETEXT, exactly like the synchronous MediaGenerationHandler path. Before
 * this, MediaJobMessageSync::attachGeneratedFile registered the audio File
 * without source text, so a later "what was said?" follow-up / knowledge-base
 * add fell through to Whisper/Tika and stored the MP3 duration instead.
 */
final class MediaJobMessageSyncTest extends TestCase
{
    private MessageRepository&MockObject $messageRepository;
    private MediaJobService&MockObject $mediaJobService;
    private GeneratedFileRegistrar&MockObject $fileRegistrar;
    private MediaJobRealtimeNotifier&MockObject $realtimeNotifier;
    private MediaJobUsageRecorder&MockObject $usageRecorder;
    private TaskPlanStore&MockObject $taskPlanStore;
    private ThumbnailService&MockObject $thumbnailService;
    private EntityManagerInterface&MockObject $em;
    private MediaJobMessageSync $sync;

    protected function setUp(): void
    {
        $this->messageRepository = $this->createMock(MessageRepository::class);
        $this->mediaJobService = $this->createMock(MediaJobService::class);
        $this->fileRegistrar = $this->createMock(GeneratedFileRegistrar::class);
        $this->realtimeNotifier = $this->createMock(MediaJobRealtimeNotifier::class);
        $this->usageRecorder = $this->createMock(MediaJobUsageRecorder::class);
        $this->taskPlanStore = $this->createMock(TaskPlanStore::class);
        $this->thumbnailService = $this->createMock(ThumbnailService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $this->sync = new MediaJobMessageSync(
            $this->messageRepository,
            $this->mediaJobService,
            $this->fileRegistrar,
            $this->realtimeNotifier,
            $this->usageRecorder,
            $this->taskPlanStore,
            $this->thumbnailService,
            $this->em,
            new NullLogger(),
        );
    }

    public function testCompletedAudioJobPersistsSpokenScriptAsFileText(): void
    {
        $captured = [];
        $this->fileRegistrar->expects(self::once())
            ->method('register')
            ->willReturnCallback($this->captureRegisterArgs($captured));

        $this->sync->syncTerminalState($this->completedJob(
            MediaJob::TYPE_AUDIO,
            'Hello from Synaplan, this is a TTS routing test.',
        ));

        self::assertSame('Hello from Synaplan, this is a TTS routing test.', $captured['fileText']);
        self::assertSame('7/2026/07/voice.mp3', $captured['relativePath']);
        self::assertSame('audio', $captured['type']);
    }

    public function testCompletedImageJobDoesNotPassFileText(): void
    {
        $captured = [];
        $this->fileRegistrar->expects(self::once())
            ->method('register')
            ->willReturnCallback($this->captureRegisterArgs($captured));

        $this->sync->syncTerminalState($this->completedJob(
            MediaJob::TYPE_IMAGE,
            'a red balloon over a city',
        ));

        // A generated image prompt is a description, not spoken content — the
        // sync path never stored it as BFILETEXT, so neither must this one.
        self::assertNull($captured['fileText']);
    }

    public function testCompletedAudioJobWithBlankPromptPassesNullFileText(): void
    {
        $captured = [];
        $this->fileRegistrar->expects(self::once())
            ->method('register')
            ->willReturnCallback($this->captureRegisterArgs($captured));

        $this->sync->syncTerminalState($this->completedJob(MediaJob::TYPE_AUDIO, '   '));

        self::assertNull($captured['fileText']);
    }

    /**
     * #1499: async-generated videos are the common case for video; without a
     * poster generated + persisted here, BTHUMBPATH stays null forever and the
     * Generated grid never shows a video frame. Mirror the inline path.
     */
    public function testCompletedVideoJobGeneratesAndPersistsPoster(): void
    {
        $registered = new File();
        $this->fileRegistrar->method('register')->willReturn($registered);

        $this->thumbnailService->expects(self::once())
            ->method('generateThumbnail')
            ->with('7/2026/07/clip.mp4')
            ->willReturn('7/2026/07/clip_thumb.jpg');

        $this->sync->syncTerminalState($this->completedJob(
            MediaJob::TYPE_VIDEO,
            'a timelapse of a city at night',
            '/api/v1/files/uploads/7/2026/07/clip.mp4',
        ));

        self::assertSame('7/2026/07/clip_thumb.jpg', $registered->getThumbPath());
    }

    /**
     * Non-video jobs must never trigger ffmpeg poster extraction.
     */
    public function testCompletedAudioJobDoesNotGeneratePoster(): void
    {
        $this->fileRegistrar->method('register')->willReturn(new File());
        $this->thumbnailService->expects(self::never())->method('generateThumbnail');

        $this->sync->syncTerminalState($this->completedJob(
            MediaJob::TYPE_AUDIO,
            'Hello from Synaplan.',
        ));
    }

    /**
     * @param array<string, mixed> $captured
     */
    private function captureRegisterArgs(array &$captured): callable
    {
        return function (
            int $userId,
            ?string $relativePath,
            string $type,
            ?int $messageId = null,
            ?string $provider = null,
            bool $ephemeral = false,
            ?string $fileText = null,
        ) use (&$captured): File {
            $captured = compact('userId', 'relativePath', 'type', 'messageId', 'fileText');

            return new File();
        };
    }

    private function completedJob(
        string $type,
        string $prompt,
        string $url = '/api/v1/files/uploads/7/2026/07/voice.mp3',
    ): MediaJob {
        $job = (new MediaJob('job-key-1'))
            ->setUserId(7)
            ->setType($type)
            ->setMessageId(55)
            ->setPrompt($prompt)
            ->setStatus(MediaJob::STATUS_COMPLETED)
            ->setResult([
                'file' => ['url' => $url, 'type' => $type],
            ]);

        $message = new Message();
        $message->setDirection('OUT');
        // Message::getId() has no setter — assign the persisted id via reflection
        // so setMeta() (which stamps MessageMeta::$messageId) works.
        $idProp = new \ReflectionProperty(Message::class, 'id');
        $idProp->setValue($message, 55);

        $this->messageRepository->method('find')->willReturn($message);
        $this->mediaJobService->method('toStatusArray')->willReturn(['state' => 'completed']);
        $this->usageRecorder->method('record')->willReturn(null);

        return $job;
    }
}
