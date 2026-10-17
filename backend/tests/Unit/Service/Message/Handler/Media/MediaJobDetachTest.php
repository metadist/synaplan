<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Handler\Media;

use App\Entity\Message;
use App\Service\Media\MediaJob;
use App\Service\Media\MediaJobConfig;
use App\Service\Media\MediaJobDispatcher;
use App\Service\Media\MediaJobMessageSync;
use App\Service\Media\MediaJobService;
use App\Service\Message\Handler\Media\MediaJobDetach;
use App\Service\Message\Handler\Media\MediaModel;
use App\Service\Message\Handler\Media\MediaReferences;
use App\Service\Message\Handler\Media\MediaTurnRequest;
use App\Service\Message\Handler\MediaErrorMessageBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MediaJobDetachTest extends TestCase
{
    /** @var list<mixed> */
    private array $log = [];

    public function testAForcedInlineRenderNeverDetaches(): void
    {
        self::assertSame([false, true], [
            $this->detacher()->allowed($this->request(options: ['force_inline_media' => true])),
            $this->detacher()->allowed($this->request()),
        ]);
    }

    public function testAJobCarriesTheTurnAndItsBillableUsage(): void
    {
        $answer = $this->detacher()->detach(MediaJob::TYPE_IMAGE, $this->request(options: ['node_id' => ' n2 ', 'track_id' => 'track-1', 'quality' => 'hd']), ['quality' => 'high', 'size' => '1536x1024'], '/uploads/photo.png', $this->stream(...), null);

        self::assertSame(['create', 'dispatch', 'stream __IMAGE_GENERATING__'], array_map(static fn (array $entry): string => $entry[0], $this->log));
        self::assertSame([
            'userId' => 7,
            'type' => 'image',
            'provider' => 'openai',
            'prompt' => 'A fox',
            'modelId' => 41,
            'model' => 'gpt-image-1',
            'chatId' => null,
            'messageId' => 1001,
            'nodeId' => 'n2',
            'trackId' => 'track-1',
            'inputRef' => '/uploads/photo.png',
            'options' => ['quality' => 'high', 'size' => '1536x1024', 'lang' => 'de', 'media_usage' => ['images' => 1, 'quality' => 'high', 'size' => '1536x1024']],
        ], $this->log[0][1]);
        self::assertSame(['job_id' => 'job-1', 'type' => 'image', 'state' => 'running'], $answer['metadata']['media_job']);
    }

    public function testVideoAndSpeechJobsBillSecondsAndCharacters(): void
    {
        $this->detacher()->detach(MediaJob::TYPE_VIDEO, $this->request(classification: ['duration' => 6]), [], null, $this->stream(...), null);
        $this->detacher()->detach(MediaJob::TYPE_AUDIO, $this->request(), [], null, $this->stream(...), null);

        self::assertSame([['duration_seconds' => 6.0], ['characters' => 5]], [$this->log[0][1]['options']['media_usage'], $this->log[3][1]['options']['media_usage']]);
        self::assertSame(['stream __VIDEO_GENERATING__', 'stream __AUDIO_GENERATING__'], [$this->log[2][0], $this->log[5][0]]);
    }

    public function testAUserAtTheJobCeilingIsToldInTheSameTurn(): void
    {
        $answer = $this->detacher(active: 16)->detach(MediaJob::TYPE_IMAGE, $this->request(), [], null, $this->stream(...), null);

        self::assertSame([['stream '.$answer['text']]], array_map(static fn (array $entry): array => [$entry[0]], $this->log));
        self::assertSame($answer['text'], $answer['metadata']['error']);
    }

    public function testAnUnreachableQueueFailsTheJobInTheSameTurn(): void
    {
        $answer = $this->detacher(delivered: false)->detach(MediaJob::TYPE_IMAGE, $this->request(), [], null, $this->stream(...), null);

        self::assertSame(['create', 'dispatch', 'failed', 'sync', 'stream '.$answer['text']], array_map(static fn (array $entry): string => $entry[0], $this->log));
        self::assertSame(['job_id' => 'job-1', 'type' => 'image', 'state' => 'failed', 'error' => $answer['text']], $answer['metadata']['media_job']);
    }

    private function stream(string $text): void
    {
        $this->log[] = ['stream '.$text];
    }

    private function detacher(int $active = 0, bool $delivered = true): MediaJobDetach
    {
        $config = $this->createStub(MediaJobConfig::class);
        $config->method('isAsyncJobsEnabled')->willReturn(true);
        $config->method('maxActiveJobsPerUser')->willReturn(16);
        $jobs = $this->createStub(MediaJobService::class);
        $jobs->method('countActiveForUser')->willReturn($active);
        $jobs->method('create')->willReturnCallback(function (array $params): MediaJob {
            $this->log[] = ['create', $params];

            return new MediaJob('job-1');
        });
        $jobs->method('markFailed')->willReturnCallback(function (): bool {
            $this->log[] = ['failed'];

            return true;
        });
        $dispatcher = $this->createStub(MediaJobDispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(function () use ($delivered): bool {
            $this->log[] = ['dispatch'];

            return $delivered;
        });
        $sync = $this->createStub(MediaJobMessageSync::class);
        $sync->method('syncTerminalState')->willReturnCallback(function (): void {
            $this->log[] = ['sync'];
        });

        return new MediaJobDetach($config, $jobs, $dispatcher, $sync, new MediaErrorMessageBuilder(), new NullLogger());
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $classification
     */
    private function request(array $options = [], array $classification = []): MediaTurnRequest
    {
        $message = $this->createStub(Message::class);
        $message->method('getId')->willReturn(1001);
        $message->method('getLanguage')->willReturn('de');

        return new MediaTurnRequest($message, [], $classification, $options, 'A fox', 'image', new MediaReferences([], [], null), new MediaModel(41, 'openai', 'gpt-image-1', []), 7);
    }
}
