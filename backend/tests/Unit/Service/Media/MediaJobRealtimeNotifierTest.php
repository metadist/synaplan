<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\Realtime\Channel\ChannelInterface;
use App\Realtime\Publisher\RealtimePublisherInterface;
use App\Service\Media\MediaJob;
use App\Service\Media\MediaJobRealtimeNotifier;
use App\Service\Media\MediaJobService;
use App\Service\Media\MediaJobStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Sprint C: a terminal/active media job publishes a `media_job.update` event to
 * the owner's per-user Centrifugo channel so the frontend resolves the banner
 * instantly (push primary) instead of waiting for the 25s poll.
 */
final class MediaJobRealtimeNotifierTest extends TestCase
{
    public function testPublishesCompletedUpdateToOwnerUserChannel(): void
    {
        $service = new MediaJobService($this->createStub(MediaJobStore::class), new NullLogger());
        $job = $service->create([
            'userId' => 7,
            'type' => MediaJob::TYPE_VIDEO,
            'provider' => 'google',
            'chatId' => 201,
            'messageId' => 305,
        ]);
        $service->markCompleted($job, [
            'file' => ['url' => '/api/v1/files/uploads/x.mp4', 'type' => 'video', 'mimeType' => 'video/mp4'],
        ]);

        $captured = null;
        $publisher = $this->createMock(RealtimePublisherInterface::class);
        $publisher->expects(self::once())
            ->method('publish')
            ->willReturnCallback(function (ChannelInterface $channel, string $eventType, array $payload) use (&$captured): void {
                $captured = ['channel' => $channel->name(), 'event' => $eventType, 'payload' => $payload];
            });

        (new MediaJobRealtimeNotifier($publisher, $service, new NullLogger()))->publishUpdate($job);

        self::assertNotNull($captured);
        self::assertSame('user:7', $captured['channel']);
        self::assertSame('media_job.update', $captured['event']);
        self::assertSame('done', $captured['payload']['state']);
        self::assertSame($job->getJobKey(), $captured['payload']['job_id']);
        self::assertSame(305, $captured['payload']['message_id']);
        self::assertSame(201, $captured['payload']['chat_id']);
        self::assertSame('video', $captured['payload']['type']);
        self::assertIsArray($captured['payload']['file']);
        self::assertSame('/api/v1/files/uploads/x.mp4', $captured['payload']['file']['url']);
    }

    public function testPublishesFailedUpdateWithLocalizedError(): void
    {
        $service = new MediaJobService($this->createStub(MediaJobStore::class), new NullLogger());
        $job = $service->create([
            'userId' => 9,
            'type' => MediaJob::TYPE_VIDEO,
            'provider' => 'google',
            'messageId' => 42,
        ]);
        $service->markFailed($job, 'Your video could not be created.');

        $captured = null;
        $publisher = $this->createMock(RealtimePublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(
            function (ChannelInterface $channel, string $eventType, array $payload) use (&$captured): void {
                $captured = $payload;
            }
        );

        (new MediaJobRealtimeNotifier($publisher, $service, new NullLogger()))->publishUpdate($job);

        self::assertSame('failed', $captured['state']);
        self::assertSame('Your video could not be created.', $captured['error']);
    }

    public function testDoesNotPublishForAnonymousUser(): void
    {
        $service = new MediaJobService($this->createStub(MediaJobStore::class), new NullLogger());
        $job = $service->create(['userId' => 0, 'type' => MediaJob::TYPE_IMAGE, 'provider' => 'openai']);

        $publisher = $this->createMock(RealtimePublisherInterface::class);
        $publisher->expects(self::never())->method('publish');

        (new MediaJobRealtimeNotifier($publisher, $service, new NullLogger()))->publishUpdate($job);
    }

    public function testNeverThrowsWhenPublisherFails(): void
    {
        $service = new MediaJobService($this->createStub(MediaJobStore::class), new NullLogger());
        $job = $service->create(['userId' => 5, 'type' => MediaJob::TYPE_AUDIO, 'provider' => 'piper', 'messageId' => 1]);

        $publisher = $this->createMock(RealtimePublisherInterface::class);
        $publisher->method('publish')->willThrowException(new \RuntimeException('centrifugo down'));

        // Must swallow — realtime is best-effort, never breaks the terminal sync.
        $this->expectNotToPerformAssertions();
        (new MediaJobRealtimeNotifier($publisher, $service, new NullLogger()))->publishUpdate($job);
    }
}
