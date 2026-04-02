<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\AI\Exception\ProviderException;
use App\AI\Service\AiFacade;
use App\Entity\Model;
use App\Entity\User;
use App\Service\Exception\NoModelAvailableException;
use App\Service\Exception\RateLimitExceededException;
use App\Service\File\UserUploadPathBuilder;
use App\Service\MediaGenerationService;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;

class MediaGenerationServiceTest extends TestCase
{
    private AiFacade&MockObject $aiFacade;
    private ModelConfigService&MockObject $modelConfigService;
    private RateLimitService&MockObject $rateLimitService;
    private UserUploadPathBuilder $pathBuilder;
    private EntityManagerInterface&MockObject $em;
    private CacheItemPoolInterface&MockObject $cache;
    private MediaGenerationService $service;
    private string $uploadDir;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->rateLimitService = $this->createMock(RateLimitService::class);
        $this->pathBuilder = new UserUploadPathBuilder();
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->cache = $this->createMock(CacheItemPoolInterface::class);
        $this->uploadDir = sys_get_temp_dir().'/synaplan_test_'.uniqid();
        mkdir($this->uploadDir, 0777, true);

        $this->service = new MediaGenerationService(
            $this->aiFacade,
            $this->modelConfigService,
            $this->rateLimitService,
            $this->pathBuilder,
            $this->em,
            new NullLogger(),
            $this->cache,
            $this->uploadDir,
        );
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->uploadDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function createUser(int $id = 1): User&MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }

    private function createModel(string $service = 'OpenAI', string $providerId = 'dall-e-3', string $name = 'DALL-E 3'): Model&MockObject
    {
        $model = $this->createMock(Model::class);
        $model->method('getService')->willReturn($service);
        $model->method('getProviderId')->willReturn($providerId);
        $model->method('getName')->willReturn($name);
        $model->method('getJson')->willReturn(['params' => ['model' => $providerId]]);

        return $model;
    }

    private function allowRateLimit(): void
    {
        $this->rateLimitService->method('checkLimit')->willReturn([
            'allowed' => true,
            'limit' => 100,
            'used' => 0,
            'remaining' => 100,
        ]);
    }

    public function testStartVideoGenerationSuccess(): void
    {
        $user = $this->createUser(1);
        $this->allowRateLimit();

        $model = $this->createModel('Google', 'veo-3.1', 'Veo 3.1');
        $this->setUpModelResolution(45, $model);
        $this->modelConfigService->method('getDefaultModel')->with('TEXT2VID', 1)->willReturn(45);

        $this->aiFacade->expects($this->once())
            ->method('startVideoGeneration')
            ->with('test prompt', 1, [
                'provider' => 'google',
                'model' => 'veo-3.1',
                'duration' => 8,
                'aspect_ratio' => '16:9',
            ])
            ->willReturn([
                'operationName' => 'ops/123',
                'provider' => 'google',
                'model' => 'veo-3.1',
                'duration' => 8,
            ]);

        $cacheItem = $this->createMock(\Psr\Cache\CacheItemInterface::class);
        $cacheItem->expects($this->once())->method('set')->with($this->callback(function ($data) {
            return 'ops/123' === $data['operationName'] && 1 === $data['userId'] && 'test prompt' === $data['prompt'] && 45 === $data['modelId'];
        }));
        $cacheItem->expects($this->once())->method('expiresAfter')->with(1200);

        $this->cache->expects($this->once())
            ->method('getItem')
            ->with($this->stringStartsWith('video_job_'))
            ->willReturn($cacheItem);

        $this->cache->expects($this->once())->method('save')->with($cacheItem);

        $result = $this->service->startVideoGeneration($user, 'test prompt');

        $this->assertArrayHasKey('jobId', $result);
        $this->assertSame('processing', $result['status']);
        $this->assertSame('google', $result['provider']);
        $this->assertSame('veo-3.1', $result['model']);
    }

    public function testCheckVideoJobProcessing(): void
    {
        $user = $this->createUser(1);
        $jobId = 'aabbccdd11223344aabbccdd11223344';

        $cacheItem = $this->createMock(\Psr\Cache\CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn([
            'operationName' => 'ops/123',
            'status' => 'processing',
            'provider' => 'google',
            'model' => 'veo-3.1',
            'duration' => 8,
            'userId' => 1,
            'prompt' => 'test prompt',
            'startedAt' => time() - 10,
        ]);

        $this->cache->method('getItem')->with('video_job_'.$jobId)->willReturn($cacheItem);

        $this->aiFacade->expects($this->once())
            ->method('pollVideoOperation')
            ->with('ops/123', 'google')
            ->willReturn(['done' => false, 'videoUri' => null, 'error' => null]);

        $result = $this->service->checkVideoJob($user, $jobId);

        $this->assertSame('processing', $result['status']);
        $this->assertGreaterThanOrEqual(10, $result['elapsed_seconds']);
    }

    public function testCheckVideoJobCompleted(): void
    {
        $user = $this->createUser(1);
        $jobId = 'aabbccdd11223344aabbccdd11223344';

        $cacheItem = $this->createMock(\Psr\Cache\CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn([
            'operationName' => 'ops/123',
            'status' => 'processing',
            'provider' => 'google',
            'model' => 'veo-3.1',
            'duration' => 8,
            'userId' => 1,
            'prompt' => 'test prompt',
            'startedAt' => time() - 20,
        ]);

        $this->cache->expects($this->exactly(3))
            ->method('getItem')
            ->with('video_job_'.$jobId)
            ->willReturn($cacheItem);

        $this->cache->expects($this->exactly(2))
            ->method('save')
            ->with($cacheItem);

        $this->aiFacade->expects($this->once())
            ->method('pollVideoOperation')
            ->with('ops/123', 'google')
            ->willReturn(['done' => true, 'videoUri' => 'https://example.com/vid.mp4', 'error' => null]);

        $this->aiFacade->expects($this->once())
            ->method('downloadVideoRaw')
            ->with('https://example.com/vid.mp4', 'google')
            ->willReturn('fake-mp4-bytes');

        $this->rateLimitService->expects($this->once())->method('recordUsage');

        $result = $this->service->checkVideoJob($user, $jobId);

        $this->assertSame('completed', $result['status']);
        $this->assertArrayHasKey('file', $result);
        $this->assertSame('video', $result['file']['type']);
        $this->assertStringContainsString('.mp4', $result['file']['url']);
        $this->assertSame('google', $result['provider']);
    }

    public function testCheckVideoJobCompletedReturnsCachedResultWithoutPollingAgain(): void
    {
        $user = $this->createUser(1);
        $jobId = 'aabbccdd11223344aabbccdd11223344';
        $cachedResult = [
            'status' => 'completed',
            'file' => [
                'url' => '/api/v1/files/uploads/test.mp4',
                'type' => 'video',
                'mimeType' => 'video/mp4',
            ],
            'provider' => 'google',
            'model' => 'veo-3.1',
            'elapsed_seconds' => 25,
        ];

        $cacheItem = $this->createMock(\Psr\Cache\CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn([
            'operationName' => 'ops/123',
            'status' => 'completed',
            'provider' => 'google',
            'model' => 'veo-3.1',
            'duration' => 8,
            'userId' => 1,
            'prompt' => 'test prompt',
            'startedAt' => time() - 25,
            'result' => $cachedResult,
        ]);

        $this->cache->expects($this->once())
            ->method('getItem')
            ->with('video_job_'.$jobId)
            ->willReturn($cacheItem);

        $this->aiFacade->expects($this->never())->method('pollVideoOperation');
        $this->aiFacade->expects($this->never())->method('downloadVideoRaw');
        $this->rateLimitService->expects($this->never())->method('recordUsage');

        $result = $this->service->checkVideoJob($user, $jobId);

        $this->assertSame($cachedResult, $result);
    }

    public function testCheckVideoJobSafetyExceptionTransitionsToFailed(): void
    {
        $user = $this->createUser(1);
        $jobId = 'aabbccdd11223344aabbccdd11223344';

        $cacheItem = $this->createMock(\Psr\Cache\CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn([
            'operationName' => 'ops/123',
            'status' => 'processing',
            'provider' => 'google',
            'model' => 'veo-3.1',
            'duration' => 8,
            'userId' => 1,
            'prompt' => 'test prompt',
            'startedAt' => time() - 15,
        ]);

        $this->cache->expects($this->exactly(2))
            ->method('getItem')
            ->with('video_job_'.$jobId)
            ->willReturn($cacheItem);

        $this->cache->expects($this->once())->method('save')->with($cacheItem);

        $this->aiFacade->expects($this->once())
            ->method('pollVideoOperation')
            ->willThrowException(new ProviderException('Content blocked: SAFETY', 'google'));

        $this->expectException(ProviderException::class);

        $this->service->checkVideoJob($user, $jobId);
    }

    public function testCheckVideoJobDownloadFailureResetsToProcessing(): void
    {
        $user = $this->createUser(1);
        $jobId = 'aabbccdd11223344aabbccdd11223344';

        $cacheItem = $this->createMock(\Psr\Cache\CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn([
            'operationName' => 'ops/123',
            'status' => 'processing',
            'provider' => 'google',
            'model' => 'veo-3.1',
            'duration' => 8,
            'userId' => 1,
            'prompt' => 'test prompt',
            'startedAt' => time() - 30,
        ]);

        $this->cache->expects($this->exactly(3))
            ->method('getItem')
            ->with('video_job_'.$jobId)
            ->willReturn($cacheItem);

        $setCallIndex = 0;
        $cacheItem->expects($this->exactly(2))
            ->method('set')
            ->with($this->callback(function ($data) use (&$setCallIndex) {
                ++$setCallIndex;
                if (1 === $setCallIndex) {
                    return 'finalizing' === $data['status'];
                }

                return 'processing' === $data['status'];
            }));

        $this->cache->expects($this->exactly(2))->method('save')->with($cacheItem);

        $this->aiFacade->expects($this->once())
            ->method('pollVideoOperation')
            ->willReturn(['done' => true, 'videoUri' => 'https://example.com/vid.mp4', 'error' => null]);

        $this->aiFacade->expects($this->once())
            ->method('downloadVideoRaw')
            ->willThrowException(new \RuntimeException('Network timeout'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Video download/save failed');

        $this->service->checkVideoJob($user, $jobId);
    }

    private function setUpModelResolution(int $modelId, ?Model $model): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->with($modelId)->willReturn($model);
        $this->em->method('getRepository')->with(Model::class)->willReturn($repo);
    }

    public function testEmptyPromptThrowsInvalidArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Prompt is required');

        $this->service->generate($this->createUser(), '  ', 'image');
    }

    public function testInvalidTypeThrowsInvalidArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Type must be "image" or "video"');

        $this->service->generate($this->createUser(), 'sunset', 'audio');
    }

    public function testRateLimitExceededThrowsDedicatedException(): void
    {
        $this->rateLimitService->method('checkLimit')->willReturn([
            'allowed' => false,
            'limit' => 10,
            'used' => 10,
            'remaining' => 0,
        ]);

        $this->expectException(RateLimitExceededException::class);
        $this->expectExceptionMessage('Rate limit exceeded');

        $this->service->generate($this->createUser(), 'sunset', 'image');
    }

    public function testNoDefaultModelThrowsDedicatedException(): void
    {
        $this->allowRateLimit();
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $this->expectException(NoModelAvailableException::class);

        $this->service->generate($this->createUser(), 'sunset', 'image');
    }

    public function testModelNotFoundThrowsDedicatedException(): void
    {
        $this->allowRateLimit();
        $this->setUpModelResolution(999, null);

        $this->expectException(NoModelAvailableException::class);
        $this->expectExceptionMessage('Model not found: 999');

        $this->service->generate($this->createUser(), 'sunset', 'image', 999);
    }

    public function testSuccessfulImageGenerationWithDataUrl(): void
    {
        $this->allowRateLimit();
        $model = $this->createModel();
        $this->setUpModelResolution(42, $model);

        $pngHeader = base64_encode("\x89PNG\r\n\x1a\n".str_repeat("\0", 100));
        $this->aiFacade->method('generateImage')->willReturn([
            'images' => ['data:image/png;base64,'.$pngHeader],
            'provider' => 'openai',
            'model' => 'dall-e-3',
        ]);

        $result = $this->service->generate($this->createUser(), 'a sunset', 'image', 42);

        self::assertTrue($result['success']);
        self::assertSame('image', $result['file']['type']);
        self::assertSame('image/png', $result['file']['mimeType']);
        self::assertStringStartsWith('/api/v1/files/uploads/', $result['file']['url']);
        self::assertSame('openai', $result['provider']);
    }

    public function testSuccessfulImageGenerationWithB64Json(): void
    {
        $this->allowRateLimit();
        $model = $this->createModel();
        $this->setUpModelResolution(42, $model);

        $pngData = "\x89PNG\r\n\x1a\n".str_repeat("\0", 100);
        $b64 = base64_encode($pngData);
        $this->aiFacade->method('generateImage')->willReturn([
            'images' => [['b64_json' => $b64, 'content_type' => 'image/png']],
            'provider' => 'openai',
            'model' => 'gpt-image-1.5',
        ]);

        $result = $this->service->generate($this->createUser(), 'a sunset', 'image', 42);

        self::assertTrue($result['success']);
        self::assertSame('image/png', $result['file']['mimeType']);
    }

    public function testB64JsonWithoutContentTypeDefaultsToPng(): void
    {
        $this->allowRateLimit();
        $model = $this->createModel();
        $this->setUpModelResolution(42, $model);

        $pngData = "\x89PNG\r\n\x1a\n".str_repeat("\0", 100);
        $b64 = base64_encode($pngData);
        $this->aiFacade->method('generateImage')->willReturn([
            'images' => [['b64_json' => $b64]],
        ]);

        $result = $this->service->generate($this->createUser(), 'a sunset', 'image', 42);

        self::assertTrue($result['success']);
        self::assertSame('image/png', $result['file']['mimeType']);
    }

    public function testProviderExceptionPropagates(): void
    {
        $this->allowRateLimit();
        $model = $this->createModel();
        $this->setUpModelResolution(42, $model);

        $this->aiFacade->method('generateImage')
            ->willThrowException(new ProviderException('API quota exhausted', 'openai'));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('API quota exhausted');

        $this->service->generate($this->createUser(), 'sunset', 'image', 42);
    }

    public function testEmptyProviderResultThrowsRuntime(): void
    {
        $this->allowRateLimit();
        $model = $this->createModel();
        $this->setUpModelResolution(42, $model);

        $this->aiFacade->method('generateImage')->willReturn([
            'images' => [],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Provider returned no media');

        $this->service->generate($this->createUser(), 'sunset', 'image', 42);
    }

    public function testVideoGenerationCallsCorrectFacadeMethod(): void
    {
        $this->allowRateLimit();
        $model = $this->createModel('Google', 'veo-3.1', 'Veo 3.1');
        $this->setUpModelResolution(45, $model);

        $mp4Data = str_repeat("\0", 100);
        $b64 = base64_encode($mp4Data);
        $this->aiFacade->expects(self::once())
            ->method('generateVideo')
            ->willReturn([
                'videos' => ['data:video/mp4;base64,'.$b64],
                'provider' => 'google',
                'model' => 'veo-3.1',
            ]);

        $result = $this->service->generate($this->createUser(), 'a timelapse', 'video', 45);

        self::assertTrue($result['success']);
        self::assertSame('video', $result['file']['type']);
    }

    public function testRecordsUsageAfterSuccess(): void
    {
        $this->allowRateLimit();
        $model = $this->createModel();
        $this->setUpModelResolution(42, $model);

        $pngData = "\x89PNG\r\n\x1a\n".str_repeat("\0", 100);
        $this->aiFacade->method('generateImage')->willReturn([
            'images' => ['data:image/png;base64,'.base64_encode($pngData)],
        ]);

        $this->rateLimitService->expects(self::once())
            ->method('recordUsage')
            ->with(
                self::anything(),
                'IMAGES',
                self::callback(fn (array $meta) => 'openai' === $meta['provider'] && 42 === $meta['model_id']),
            );

        $this->service->generate($this->createUser(), 'sunset', 'image', 42);
    }

    // ==================== Pic2pic Tests ====================

    public function testPic2picEmptyPromptThrowsInvalidArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Prompt is required');

        $this->service->generateFromImages($this->createUser(), '  ', ['/tmp/a.png']);
    }

    public function testPic2picNoImagesThrowsInvalidArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('1 or 2 images are required');

        $this->service->generateFromImages($this->createUser(), 'combine these', []);
    }

    public function testPic2picTooManyImagesThrowsInvalidArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('1 or 2 images are required');

        $this->service->generateFromImages($this->createUser(), 'combine these', ['/tmp/a.png', '/tmp/b.png', '/tmp/c.png']);
    }

    public function testPic2picMissingImageFileThrowsInvalidArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Uploaded image not found/i');

        $this->service->generateFromImages($this->createUser(), 'combine', ['/tmp/nonexistent_pic2pic_test.png']);
    }

    public function testPic2picPassesImagesToFacadeViaOptions(): void
    {
        $this->allowRateLimit();
        $model = $this->createModel('OpenAI', 'gpt-image-1.5', 'GPT Image 1.5');
        $this->setUpModelResolution(151, $model);

        // Create real temp files
        $tmpFile1 = tempnam(sys_get_temp_dir(), 'pic2pic_test_');
        $tmpFile2 = tempnam(sys_get_temp_dir(), 'pic2pic_test_');
        file_put_contents($tmpFile1, "\x89PNG\r\n\x1a\n".str_repeat("\0", 50));
        file_put_contents($tmpFile2, "\x89PNG\r\n\x1a\n".str_repeat("\0", 50));

        $pngData = "\x89PNG\r\n\x1a\n".str_repeat("\0", 100);
        $this->aiFacade->expects(self::once())
            ->method('generateImage')
            ->with(
                'put object from image 1 into scene of image 2',
                self::anything(),
                self::callback(fn (array $opts) => isset($opts['images']) && 2 === \count($opts['images'])),
            )
            ->willReturn([
                'images' => ['data:image/png;base64,'.base64_encode($pngData)],
                'provider' => 'openai',
                'model' => 'gpt-image-1.5',
            ]);

        $result = $this->service->generateFromImages(
            $this->createUser(),
            'put object from image 1 into scene of image 2',
            [$tmpFile1, $tmpFile2],
            151,
        );

        self::assertTrue($result['success']);
        self::assertSame('image', $result['file']['type']);
        self::assertSame('image/png', $result['file']['mimeType']);
        self::assertSame('openai', $result['provider']);

        @unlink($tmpFile1);
        @unlink($tmpFile2);
    }

    public function testPic2picWithSingleImage(): void
    {
        $this->allowRateLimit();
        $model = $this->createModel('Google', 'gemini-3.1-flash-image-preview', 'Nano Banana 2');
        $this->setUpModelResolution(173, $model);

        $tmpFile = tempnam(sys_get_temp_dir(), 'pic2pic_test_');
        file_put_contents($tmpFile, "\x89PNG\r\n\x1a\n".str_repeat("\0", 50));

        $pngData = "\x89PNG\r\n\x1a\n".str_repeat("\0", 100);
        $this->aiFacade->expects(self::once())
            ->method('generateImage')
            ->with(
                'transform this into watercolor',
                self::anything(),
                self::callback(fn (array $opts) => isset($opts['images']) && 1 === \count($opts['images'])),
            )
            ->willReturn([
                'images' => ['data:image/png;base64,'.base64_encode($pngData)],
                'provider' => 'google',
                'model' => 'gemini-3.1-flash-image-preview',
            ]);

        $result = $this->service->generateFromImages(
            $this->createUser(),
            'transform this into watercolor',
            [$tmpFile],
            173,
        );

        self::assertTrue($result['success']);
        self::assertSame('google', $result['provider']);

        @unlink($tmpFile);
    }

    public function testPic2picUsesPic2picCapabilityFallback(): void
    {
        $this->allowRateLimit();

        // Mock the fallback to return Nano Banana 2 (190)
        $this->modelConfigService->expects(self::once())
            ->method('getDefaultModel')
            ->with('PIC2PIC', self::anything())
            ->willReturn(190);

        $model = $this->createModel('Google', 'gemini-3.1-flash-image-preview', 'Nano Banana 2');
        $this->setUpModelResolution(190, $model);

        $tmpFile = tempnam(sys_get_temp_dir(), 'pic2pic_test_');
        file_put_contents($tmpFile, "\x89PNG\r\n\x1a\n".str_repeat("\0", 50));

        $pngData = "\x89PNG\r\n\x1a\n".str_repeat("\0", 100);
        $this->aiFacade->expects(self::once())
            ->method('generateImage')
            ->willReturn([
                'images' => ['data:image/png;base64,'.base64_encode($pngData)],
                'provider' => 'google',
                'model' => 'gemini-3.1-flash-image-preview',
            ]);

        $result = $this->service->generateFromImages(
            $this->createUser(),
            'transform this into watercolor',
            [$tmpFile],
            null, // No model ID provided, should trigger fallback
        );

        self::assertTrue($result['success']);
        self::assertSame('google', $result['provider']);

        @unlink($tmpFile);
    }

    public function testPic2picProviderExceptionPropagates(): void
    {
        $this->allowRateLimit();
        $model = $this->createModel();
        $this->setUpModelResolution(42, $model);

        $tmpFile = tempnam(sys_get_temp_dir(), 'pic2pic_test_');
        file_put_contents($tmpFile, "\x89PNG\r\n\x1a\n".str_repeat("\0", 50));

        $this->aiFacade->method('generateImage')
            ->willThrowException(new ProviderException('Content policy violation', 'openai'));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Content policy violation');

        try {
            $this->service->generateFromImages($this->createUser(), 'combine', [$tmpFile], 42);
        } finally {
            @unlink($tmpFile);
        }
    }
}
