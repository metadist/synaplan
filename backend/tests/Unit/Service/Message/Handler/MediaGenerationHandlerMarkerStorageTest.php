<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Handler;

use App\AI\Service\AiFacade;
use App\Entity\File;
use App\Entity\Message;
use App\Entity\Model;
use App\Entity\User;
use App\Service\BillingService;
use App\Service\File\ConversationFileCatalog;
use App\Service\File\ThumbnailService;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Media\GeneratedFileRegistrar;
use App\Service\Media\MediaCancellationStore;
use App\Service\Media\MediaJobConfig;
use App\Service\Media\MediaJobDispatcher;
use App\Service\Media\MediaJobMessageSync;
use App\Service\Media\MediaJobService;
use App\Service\Message\GeneratedMediaTextRenderer;
use App\Service\Message\Handler\MediaErrorMessageBuilder;
use App\Service\Message\Handler\MediaGenerationHandler;
use App\Service\Message\MediaPromptExtractor;
use App\Service\ModelConfigService;
use App\Service\PerfPipelineFlag;
use App\Service\PremiumFeatureGate;
use App\Service\RateLimitService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Successful image/video generation stores the marker in BTEXT and the prompt
 * only in metadata.media_prompt (issue #2281).
 */
final class MediaGenerationHandlerMarkerStorageTest extends TestCase
{
    private string $uploadDir = '';

    private AiFacade&MockObject $aiFacade;
    private MediaPromptExtractor&MockObject $promptExtractor;
    private ModelConfigService&MockObject $modelConfigService;
    private EntityManagerInterface&MockObject $em;
    private RateLimitService&MockObject $rateLimitService;
    private GeneratedFileRegistrar&MockObject $generatedFileRegistrar;
    private MediaJobConfig&MockObject $mediaJobConfig;
    private MediaGenerationHandler $handler;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/media-marker-'.bin2hex(random_bytes(4));
        mkdir($this->uploadDir, 0o775, true);

        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->promptExtractor = $this->createMock(MediaPromptExtractor::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->rateLimitService = $this->createMock(RateLimitService::class);
        $this->generatedFileRegistrar = $this->createMock(GeneratedFileRegistrar::class);
        $this->mediaJobConfig = $this->createMock(MediaJobConfig::class);
        $this->mediaJobConfig->method('isAsyncJobsEnabled')->willReturn(false);
        $this->mediaJobConfig->method('maxActiveJobsPerUser')->willReturn(16);

        $this->modelConfigService->method('getEffectiveUserIdForMessage')->willReturn(7);
        $this->rateLimitService->method('checkLimit')->willReturn(['allowed' => true]);

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
            $this->createMock(MediaJobService::class),
            $this->createMock(MediaJobDispatcher::class),
            $this->createMock(MediaJobMessageSync::class),
            $this->generatedFileRegistrar,
            new PremiumFeatureGate(new BillingService('', '')),
            $this->createMock(ConversationFileCatalog::class),
            $this->uploadDir,
            'https://app.example.test',
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->uploadDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->uploadDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->uploadDir);
        }
    }

    public function testImageStoresMarkerAndMediaPrompt(): void
    {
        $this->bootstrapImageModel();
        $this->modelConfigService->method('getDefaultModel')->willReturn(42);
        $this->promptExtractor->method('extract')->willReturn([
            'prompt' => 'a blue lake at dawn',
            'media_type' => 'image',
        ]);

        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACklEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg==';
        $this->aiFacade->expects(self::once())->method('generateImage')->willReturn([
            'images' => [['url' => 'data:image/png;base64,'.$png]],
            'provider' => 'test',
            'model' => 'test-image',
            'image_count' => 1,
        ]);

        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(55);
        $this->generatedFileRegistrar->expects(self::once())->method('register')->willReturn($file);

        $result = $this->handler->handle(
            $this->messageStub('/pic a blue lake'),
            [],
            ['topic' => 'tools:pic', 'language' => 'en', 'media_type' => 'image'],
        );

        self::assertSame(GeneratedMediaTextRenderer::MARKER_IMAGE, $result['content']);
        self::assertSame('a blue lake at dawn', $result['metadata']['media_prompt'] ?? null);
        self::assertSame('image', $result['metadata']['media_type'] ?? null);
        self::assertStringNotContainsString('Generated ', (string) $result['content']);
        self::assertStringNotContainsString('blue lake', (string) $result['content']);
    }

    public function testVideoStoresMarkerAndMediaPrompt(): void
    {
        $this->bootstrapVideoModel();
        $this->modelConfigService->method('getDefaultModel')->willReturn(42);
        $this->promptExtractor->method('extract')->willReturn([
            'prompt' => 'walking cat on a beach',
            'media_type' => 'video',
        ]);

        // Tiny data URL so download/save path is skipped for remote URLs —
        // video path still expects a url key; use a data URL the saver accepts
        // or a pre-written file via download. Prefer data: for determinism.
        $bytes = 'not-a-real-video';
        $this->aiFacade->expects(self::once())->method('generateVideo')->willReturn([
            'videos' => [['url' => 'data:video/mp4;base64,'.base64_encode($bytes)]],
            'provider' => 'test',
            'model' => 'test-video',
            'duration_seconds' => 4.0,
        ]);

        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(56);
        $this->generatedFileRegistrar->expects(self::once())->method('register')->willReturn($file);

        $result = $this->handler->handle(
            $this->messageStub('/vid walking cat'),
            [],
            ['topic' => 'tools:vid', 'language' => 'en', 'media_type' => 'video'],
        );

        self::assertSame(GeneratedMediaTextRenderer::MARKER_VIDEO, $result['content']);
        self::assertSame('walking cat on a beach', $result['metadata']['media_prompt'] ?? null);
        self::assertSame('video', $result['metadata']['media_type'] ?? null);
        self::assertStringNotContainsString('Generated ', (string) $result['content']);
        self::assertStringNotContainsString('walking cat', (string) $result['content']);
    }

    private function bootstrapImageModel(): void
    {
        $user = $this->createMock(User::class);
        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->method('find')->willReturn($user);

        $model = $this->createMock(Model::class);
        $model->method('getService')->willReturn('OpenAI');
        $model->method('getProviderId')->willReturn('gpt-image-1');
        $model->method('getName')->willReturn('GPT Image');
        $model->method('getTag')->willReturn('text2pic');
        $model->method('getJson')->willReturn([]);

        $modelRepo = $this->createMock(EntityRepository::class);
        $modelRepo->method('find')->willReturn($model);

        $this->em->method('getRepository')->willReturnMap([
            [User::class, $userRepo],
            [Model::class, $modelRepo],
        ]);
    }

    private function bootstrapVideoModel(): void
    {
        $user = $this->createMock(User::class);
        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->method('find')->willReturn($user);

        $model = $this->createMock(Model::class);
        $model->method('getService')->willReturn('OpenAI');
        $model->method('getProviderId')->willReturn('sora');
        $model->method('getName')->willReturn('Sora');
        $model->method('getTag')->willReturn('text2vid');
        $model->method('getJson')->willReturn(['default_duration' => 4]);

        $modelRepo = $this->createMock(EntityRepository::class);
        $modelRepo->method('find')->willReturn($model);

        $this->em->method('getRepository')->willReturnMap([
            [User::class, $userRepo],
            [Model::class, $modelRepo],
        ]);
    }

    private function messageStub(string $text): Message&MockObject
    {
        $message = $this->createMock(Message::class);
        $message->method('getUserId')->willReturn(7);
        $message->method('getText')->willReturn($text);
        $message->method('getLanguage')->willReturn('en');
        $message->method('getId')->willReturn(1001);
        $message->method('getChat')->willReturn(null);
        $message->method('getFile')->willReturn(0);
        $message->method('getFilePath')->willReturn('');
        $message->method('getFiles')->willReturn(new ArrayCollection());
        $message->method('getMeta')->willReturn(null);

        return $message;
    }
}
