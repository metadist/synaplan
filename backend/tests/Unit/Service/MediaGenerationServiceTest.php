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
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class MediaGenerationServiceTest extends TestCase
{
    private AiFacade $aiFacade;
    private ModelConfigService $modelConfigService;
    private RateLimitService $rateLimitService;
    private UserUploadPathBuilder $pathBuilder;
    private EntityManagerInterface $em;
    private MediaGenerationService $service;
    private string $uploadDir;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->rateLimitService = $this->createMock(RateLimitService::class);
        $this->pathBuilder = new UserUploadPathBuilder();
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->uploadDir = sys_get_temp_dir().'/synaplan_test_'.uniqid();
        mkdir($this->uploadDir, 0777, true);

        $this->service = new MediaGenerationService(
            $this->aiFacade,
            $this->modelConfigService,
            $this->rateLimitService,
            $this->pathBuilder,
            $this->em,
            new NullLogger(),
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

    private function createUser(int $id = 1): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }

    private function createModel(string $service = 'OpenAI', string $providerId = 'dall-e-3', string $name = 'DALL-E 3'): Model
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
                self::callback(fn (array $meta) => 'openai' === $meta['provider']),
            );

        $this->service->generate($this->createUser(), 'sunset', 'image', 42);
    }
}
