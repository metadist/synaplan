<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Handler\Media;

use App\AI\Exception\ProviderCancelledException;
use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Entity\User;
use App\Service\File\ThumbnailService;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Media\GeneratedFileRegistrar;
use App\Service\Media\MediaCancellationStore;
use App\Service\Media\MediaJob;
use App\Service\Media\MediaJobConfig;
use App\Service\Media\MediaJobDispatcher;
use App\Service\Media\MediaJobMessageSync;
use App\Service\Media\MediaJobService;
use App\Service\Message\Handler\Media\GeneratedMediaStore;
use App\Service\Message\Handler\Media\MediaDownloader;
use App\Service\Message\Handler\Media\MediaGenerationRun;
use App\Service\Message\Handler\Media\MediaJobDetach;
use App\Service\Message\Handler\Media\MediaModel;
use App\Service\Message\Handler\Media\MediaReferences;
use App\Service\Message\Handler\Media\MediaTurnBilling;
use App\Service\Message\Handler\Media\MediaTurnRequest;
use App\Service\Message\Handler\Media\MediaUploadStore;
use App\Service\Message\Handler\MediaErrorMessageBuilder;
use App\Service\RateLimitService;
use App\Service\Usage\RecordedUsage;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MediaGenerationRunTest extends TestCase
{
    /** @var list<mixed> */
    private array $log = [];

    private string $uploadDir = '';

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/media-run-'.bin2hex(random_bytes(4));
        mkdir($this->uploadDir);
    }

    protected function tearDown(): void
    {
        $paths = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->uploadDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($paths as $path) {
            $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }
        rmdir($this->uploadDir);
    }

    public function testAStoppedRenderIsReportedAsCancelledAndStillBilled(): void
    {
        $answer = $this->generation(fails: new ProviderCancelledException('The render was cancelled', 'google'))
            ->execute($this->request('video', ['record_media_usage' => true, 'duration' => 4]), $this->createStub(User::class), $this->stream(...), null);

        self::assertTrue($answer['metadata']['cancelled']);
        self::assertSame(['duration_seconds' => 4.0], $answer['metadata']['media_usage']);
        self::assertSame(['ai generateVideo', 'usage VIDEOS'], array_column($this->log, 0));
    }

    public function testAFailedRenderIsToldInOneSentence(): void
    {
        $answer = $this->generation(fails: new \RuntimeException('Provider rejected the request'))
            ->execute($this->request('image'), null, $this->stream(...), null);

        self::assertSame('Provider rejected the request', $answer['metadata']['error']);
        self::assertSame(['ai generateImage', 'stream'], array_map(static fn (array $entry): string => $entry[0], $this->log));
    }

    public function testAVideoRendersInlineWhereTheProviderCannotRenderInTheBackground(): void
    {
        $this->generation(async: true, asyncVideo: false)->execute($this->request('video'), null, $this->stream(...), null);

        self::assertSame('ai generateVideo', $this->log[0][0]);
    }

    public function testAVideoGoesToAJobWhereTheProviderRendersInTheBackground(): void
    {
        $this->generation(async: true, asyncVideo: true)->execute($this->request('video'), null, $this->stream(...), null);

        self::assertSame(['job video', 'stream'], array_column($this->log, 0));
    }

    public function testAnInlineRenderWatchesForAStop(): void
    {
        $this->generation()->execute($this->request('image', ['track_id' => 'track-1']), null, $this->stream(...), null);

        self::assertArrayHasKey('cancel_check', $this->log[0][1]);
    }

    public function testAJobIsNeverHandedTheStopProbe(): void
    {
        $this->generation(async: true)->execute($this->request('image', ['track_id' => 'track-1']), null, $this->stream(...), null);

        self::assertArrayNotHasKey('cancel_check', $this->log[0][1]);
    }

    private function stream(string $text): void
    {
        $this->log[] = ['stream', $text];
    }

    private function generation(?\Exception $fails = null, bool $async = false, bool $asyncVideo = false): MediaGenerationRun
    {
        $ai = $this->createStub(AiFacade::class);
        $ai->method('supportsAsyncVideo')->willReturn($asyncVideo);
        $ai->method('supportsInlineReferenceImage')->willReturn(false);
        foreach (['generateImage', 'generateVideo'] as $method) {
            $ai->method($method)->willReturnCallback(function (string $prompt, int $userId, array $options) use ($method, $fails): array {
                $this->log[] = ['ai '.$method, $options];
                if (null !== $fails) {
                    throw $fails;
                }

                return ['images' => [['url' => 'https://cdn.provider.test/fox.png']], 'videos' => [['url' => 'https://cdn.provider.test/clip.mp4']], 'provider' => 'openai'];
            });
        }
        $config = $this->createStub(MediaJobConfig::class);
        $config->method('isAsyncJobsEnabled')->willReturn($async);
        $config->method('maxActiveJobsPerUser')->willReturn(16);
        $jobs = $this->createStub(MediaJobService::class);
        $jobs->method('create')->willReturnCallback(function (array $params): MediaJob {
            $this->log[] = ['job '.$params['type'], $params['options']];

            return new MediaJob('job-1');
        });
        $dispatcher = $this->createStub(MediaJobDispatcher::class);
        $dispatcher->method('dispatch')->willReturn(true);
        $limits = $this->createStub(RateLimitService::class);
        $limits->method('recordUsage')->willReturnCallback(function (User $user, string $action): RecordedUsage {
            $this->log[] = ['usage '.$action];

            return new RecordedUsage('0.040000', '0.040000', 0, 0, 0);
        });
        $downloader = $this->createStub(MediaDownloader::class);
        $downloader->method('fetch')->willReturn('bytes');
        $logger = new NullLogger();
        $uploads = new MediaUploadStore(new UserUploadPathBuilder(), $downloader, $logger, $this->uploadDir);
        $errors = new MediaErrorMessageBuilder();

        return new MediaGenerationRun(
            $ai,
            new MediaJobDetach($config, $jobs, $dispatcher, $this->createStub(MediaJobMessageSync::class), $errors, $logger),
            new GeneratedMediaStore($uploads, $this->createStub(GeneratedFileRegistrar::class), $this->createStub(ThumbnailService::class), $this->createStub(EntityManagerInterface::class), $logger, $this->uploadDir),
            new MediaTurnBilling($limits, $logger),
            $uploads,
            $this->createStub(MediaCancellationStore::class),
            $errors,
            $logger,
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function request(string $mediaType, array $options = []): MediaTurnRequest
    {
        $message = $this->createStub(Message::class);
        $message->method('getUserId')->willReturn(7);
        $message->method('getText')->willReturn('Draw a fox');

        return new MediaTurnRequest($message, [], ['language' => 'en'], $options, 'A fox', $mediaType, new MediaReferences([], [], null), new MediaModel(41, 'openai', 'gpt-image-1', []), 7);
    }
}
