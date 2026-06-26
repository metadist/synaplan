<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Handler;

use App\AI\Service\AiFacade;
use App\Entity\Model;
use App\Entity\User;
use App\Service\File\ThumbnailService;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Media\MediaCancellationStore;
use App\Service\Media\MediaJob;
use App\Service\Media\MediaJobConfig;
use App\Service\Media\MediaJobDispatcher;
use App\Service\Media\MediaJobService;
use App\Service\Message\Handler\MediaErrorMessageBuilder;
use App\Service\Message\Handler\MediaGenerationHandler;
use App\Service\Message\MediaPromptExtractor;
use App\Service\ModelConfigService;
use App\Service\PerfPipelineFlag;
use App\Service\RateLimitService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Sprint B: when MEDIA.ASYNC_JOBS_ENABLED and the provider supports async video,
 * MediaGenerationHandler must detach to MediaJobService and return a running
 * placeholder without calling the blocking generateVideo() path.
 */
final class MediaGenerationHandlerAsyncDetachTest extends TestCase
{
    private AiFacade&MockObject $aiFacade;
    private MediaJobConfig&MockObject $mediaJobConfig;
    private MediaJobService&MockObject $mediaJobService;
    private MediaJobDispatcher&MockObject $mediaJobDispatcher;
    private MediaPromptExtractor&MockObject $promptExtractor;
    private ModelConfigService&MockObject $modelConfigService;
    private EntityManagerInterface&MockObject $em;
    private RateLimitService&MockObject $rateLimitService;
    private MediaGenerationHandler $handler;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->mediaJobConfig = $this->createMock(MediaJobConfig::class);
        $this->mediaJobService = $this->createMock(MediaJobService::class);
        $this->mediaJobDispatcher = $this->createMock(MediaJobDispatcher::class);
        $this->promptExtractor = $this->createMock(MediaPromptExtractor::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->rateLimitService = $this->createMock(RateLimitService::class);

        $this->handler = new MediaGenerationHandler(
            $this->aiFacade,
            $this->modelConfigService,
            $this->em,
            new NullLogger(),
            $this->promptExtractor,
            new UserUploadPathBuilder(),
            $this->createMock(ThumbnailService::class),
            $this->rateLimitService,
            new MediaErrorMessageBuilder(),
            $this->createMock(MessageBusInterface::class),
            $this->createMock(PerfPipelineFlag::class),
            $this->createMock(MediaCancellationStore::class),
            $this->mediaJobConfig,
            $this->mediaJobService,
            $this->mediaJobDispatcher,
            sys_get_temp_dir(),
            false,
            'https://app.example.test',
        );
    }

    public function testAsyncFlagOnDetachesVideoWithoutBlockingProvider(): void
    {
        $message = $this->messageStub();
        $this->bootstrapVideoModel();

        $this->promptExtractor->method('extract')->willReturn([
            'prompt' => 'a cat surfing',
            'media_type' => 'video',
        ]);

        $this->modelConfigService->method('getEffectiveUserIdForMessage')->willReturn(7);
        $this->modelConfigService->method('getDefaultModel')->willReturn(42);

        $this->rateLimitService->method('checkLimit')->willReturn(['allowed' => true]);

        $this->mediaJobConfig->method('isAsyncJobsEnabled')->with(7)->willReturn(true);
        $this->aiFacade->method('supportsAsyncVideo')->with('higgsfield')->willReturn(true);

        $job = new MediaJob('job-detach-1');
        $this->mediaJobService->expects(self::once())
            ->method('create')
            ->with(self::callback(static function (array $params): bool {
                return MediaJob::TYPE_VIDEO === ($params['type'] ?? null)
                    && 'higgsfield' === ($params['provider'] ?? null)
                    && 'a cat surfing' === ($params['prompt'] ?? null);
            }))
            ->willReturn($job);

        $this->mediaJobDispatcher->expects(self::once())->method('dispatch')->with($job);
        $this->aiFacade->expects(self::never())->method('generateVideo');

        $result = $this->handler->handle(
            $message,
            [],
            ['topic' => 'tools:vid', 'language' => 'en'],
        );

        self::assertSame('running', $result['metadata']['media_job']['state'] ?? null);
        self::assertSame('job-detach-1', $result['metadata']['media_job']['job_id'] ?? null);
        self::assertSame('video', $result['metadata']['media_type'] ?? null);
    }

    public function testAsyncFlagOffKeepsBlockingVideoPath(): void
    {
        $message = $this->messageStub();
        $this->bootstrapVideoModel();

        $this->promptExtractor->method('extract')->willReturn([
            'prompt' => 'a cat surfing',
            'media_type' => 'video',
        ]);

        $this->modelConfigService->method('getEffectiveUserIdForMessage')->willReturn(7);
        $this->modelConfigService->method('getDefaultModel')->willReturn(42);

        $this->rateLimitService->method('checkLimit')->willReturn(['allowed' => true]);

        $this->mediaJobConfig->method('isAsyncJobsEnabled')->with(7)->willReturn(false);
        $this->aiFacade->expects(self::never())->method('supportsAsyncVideo');

        $this->mediaJobService->expects(self::never())->method('create');
        $this->mediaJobDispatcher->expects(self::never())->method('dispatch');

        $this->aiFacade->expects(self::once())
            ->method('generateVideo')
            ->willReturn([
                'videos' => [['url' => 'data:video/mp4;base64,Zm9v']],
                'provider' => 'higgsfield',
                'model' => 'dop',
            ]);

        $result = $this->handler->handle(
            $message,
            [],
            ['topic' => 'tools:vid', 'language' => 'en'],
        );

        self::assertArrayNotHasKey('media_job', $result['metadata'] ?? []);
        self::assertSame('video', $result['metadata']['media_type'] ?? null);
    }

    private function bootstrapVideoModel(): void
    {
        $user = $this->createMock(User::class);
        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->method('find')->willReturn($user);

        $model = $this->createMock(Model::class);
        $model->method('getService')->willReturn('Higgsfield');
        $model->method('getProviderId')->willReturn('dop');
        $model->method('getName')->willReturn('DoP');
        $model->method('getJson')->willReturn(['default_duration' => 5]);

        $modelRepo = $this->createMock(EntityRepository::class);
        $modelRepo->method('find')->with(42)->willReturn($model);

        $this->em->method('getRepository')->willReturnMap([
            [User::class, $userRepo],
            [Model::class, $modelRepo],
        ]);
    }

    private function messageStub(): \App\Entity\Message&MockObject
    {
        $message = $this->createMock(\App\Entity\Message::class);
        $message->method('getUserId')->willReturn(7);
        $message->method('getText')->willReturn('/vid a cat surfing');
        $message->method('getLanguage')->willReturn('en');
        $message->method('getId')->willReturn(1001);
        $message->method('getChat')->willReturn(null);
        $message->method('getFile')->willReturn(0);
        $message->method('getFilePath')->willReturn('');
        $message->method('getFiles')->willReturn(new ArrayCollection());

        return $message;
    }
}
