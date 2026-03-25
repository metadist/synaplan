<?php

namespace App\Tests\Unit\AI\Service;

use App\AI\Provider\GoogleProvider;
use App\AI\Service\AiFacade;
use App\AI\Service\ProviderRegistry;
use App\Service\CircuitBreaker;
use App\Service\File\UserUploadPathBuilder;
use App\Service\ModelConfigService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class AiFacadeAsyncVideoTest extends TestCase
{
    private ProviderRegistry&MockObject $registry;
    private ModelConfigService&MockObject $modelConfig;
    private CircuitBreaker&MockObject $circuitBreaker;
    private UserUploadPathBuilder&MockObject $pathBuilder;
    private AiFacade $facade;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(ProviderRegistry::class);
        $this->modelConfig = $this->createMock(ModelConfigService::class);
        $this->circuitBreaker = $this->createMock(CircuitBreaker::class);
        $this->pathBuilder = $this->createMock(UserUploadPathBuilder::class);

        $this->facade = new AiFacade(
            $this->registry,
            $this->modelConfig,
            $this->circuitBreaker,
            new NullLogger(),
            $this->pathBuilder,
            '/tmp'
        );
    }

    public function testStartVideoGenerationSuccess(): void
    {
        $provider = $this->createMock(GoogleProvider::class);
        $provider->method('getName')->willReturn('google');
        $provider->expects($this->once())
            ->method('startVideoOperation')
            ->with('test prompt', ['provider' => 'google'])
            ->willReturn([
                'operationName' => 'ops/123',
                'model' => 'veo-3.1',
                'duration' => 8,
            ]);

        $this->registry->method('getVideoGenerationProvider')
            ->with('google')
            ->willReturn($provider);

        $result = $this->facade->startVideoGeneration('test prompt', 1, ['provider' => 'google']);

        $this->assertSame('ops/123', $result['operationName']);
        $this->assertSame('google', $result['provider']);
        $this->assertSame('veo-3.1', $result['model']);
        $this->assertSame(8, $result['duration']);
    }

    public function testPollVideoOperationSuccess(): void
    {
        $provider = $this->createMock(GoogleProvider::class);
        $provider->method('getName')->willReturn('google');
        $provider->expects($this->once())
            ->method('pollVideoOperationOnce')
            ->with('ops/123')
            ->willReturn([
                'done' => true,
                'videoUri' => 'https://example.com/video.mp4',
                'error' => null,
            ]);

        $this->registry->method('getVideoGenerationProvider')
            ->with('google')
            ->willReturn($provider);

        $result = $this->facade->pollVideoOperation('ops/123', 'google');

        $this->assertTrue($result['done']);
        $this->assertSame('https://example.com/video.mp4', $result['videoUri']);
        $this->assertNull($result['error']);
    }

    public function testDownloadVideoContentSuccess(): void
    {
        $provider = $this->createMock(GoogleProvider::class);
        $provider->method('getName')->willReturn('google');
        $provider->expects($this->once())
            ->method('downloadVideoContent')
            ->with('https://example.com/video.mp4')
            ->willReturn('data:video/mp4;base64,dGVzdA==');

        $this->registry->method('getVideoGenerationProvider')
            ->with('google')
            ->willReturn($provider);

        $result = $this->facade->downloadVideoContent('https://example.com/video.mp4', 'google');

        $this->assertSame('data:video/mp4;base64,dGVzdA==', $result);
    }
}
