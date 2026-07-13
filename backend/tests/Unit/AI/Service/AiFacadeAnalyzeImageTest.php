<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Service;

use App\AI\Credential\HiggsfieldCredentialResolver;
use App\AI\Exception\ProviderException;
use App\AI\Interface\VisionProviderInterface;
use App\AI\Service\AiFacade;
use App\AI\Service\ProviderRegistry;
use App\Service\CircuitBreaker;
use App\Service\DiscordNotificationService;
use App\Service\File\UserUploadPathBuilder;
use App\Service\InternalEmailService;
use App\Service\ModelConfigService;
use App\Service\Usage\TranscriptionUsageRecorder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Regression tests for AiFacade::analyzeImage() provider/model selection.
 *
 * Covers PR #923 (PIC2TEXT honoured) plus the Copilot follow-ups: the
 * per-candidate option scrub so a model string intended for provider A is
 * never forwarded to fallback provider B.
 */
class AiFacadeAnalyzeImageTest extends TestCase
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

        // Pass-through circuit breaker: just invoke the callback.
        $this->circuitBreaker->method('execute')
            ->willReturnCallback(fn (callable $cb) => $cb());

        $this->facade = new AiFacade(
            $this->registry,
            $this->modelConfig,
            $this->circuitBreaker,
            new NullLogger(),
            $this->pathBuilder,
            $this->createMock(DiscordNotificationService::class),
            $this->createMock(InternalEmailService::class),
            $this->createMock(CacheInterface::class),
            $this->createMock(CacheItemPoolInterface::class),
            $this->createMock(HiggsfieldCredentialResolver::class),
            $this->createMock(TranscriptionUsageRecorder::class),
            '/tmp'
        );
    }

    public function testAnalyzeImageHonoursPic2TextProviderAndModel(): void
    {
        $this->modelConfig->expects(self::any())->method('resolveVisionDefault')
            ->with(42)
            ->willReturn([
                'provider' => 'groq',
                'model' => 'llama-4-scout-17b-16e-instruct',
                'model_id' => 123,
            ]);

        $this->registry->method('getAvailableProviders')
            ->willReturn(['groq']);

        $groq = $this->mockVisionProvider('groq');
        $groq->expects($this->once())
            ->method('explainImage')
            ->with(
                'image.jpg',
                'describe',
                $this->callback(function (array $opts): bool {
                    return 'groq' === $opts['provider']
                        && 'llama-4-scout-17b-16e-instruct' === $opts['model'];
                })
            )
            ->willReturn('a cat');

        $this->registry->expects(self::any())->method('getVisionProvider')
            ->with('groq', $this->anything())
            ->willReturn($groq);

        $result = $this->facade->analyzeImage('image.jpg', 'describe', 42);

        $this->assertSame('a cat', $result['content']);
        $this->assertSame('groq', $result['provider']);
        $this->assertSame('llama-4-scout-17b-16e-instruct', $result['model']);
    }

    public function testAnalyzeImageDoesNotLeakPic2TextModelToFallbackProvider(): void
    {
        $this->modelConfig->expects(self::any())->method('resolveVisionDefault')
            ->with(42)
            ->willReturn([
                'provider' => 'groq',
                'model' => 'llama-4-scout-17b-16e-instruct',
                'model_id' => 123,
            ]);

        $this->registry->method('getAvailableProviders')
            ->willReturn(['openai']);

        $groq = $this->mockVisionProvider('groq');
        $groq->method('explainImage')
            ->willThrowException(new ProviderException('boom', 'groq'));

        $openai = $this->mockVisionProvider('openai');
        $openai->expects($this->once())
            ->method('explainImage')
            ->with(
                'image.jpg',
                'describe',
                $this->callback(function (array $opts): bool {
                    // Crucial: the Groq model string must NOT be sent to OpenAI,
                    // otherwise OpenAI 400s on an unknown model id.
                    return 'openai' === $opts['provider']
                        && !array_key_exists('model', $opts);
                })
            )
            ->willReturn('a cat (via openai)');

        $this->registry->method('getVisionProvider')
            ->willReturnCallback(fn (string $name) => match (strtolower($name)) {
                'groq' => $groq,
                'openai' => $openai,
                default => throw new ProviderException("Unknown: $name", 'test'),
            });

        $result = $this->facade->analyzeImage('image.jpg', 'describe', 42);

        $this->assertSame('a cat (via openai)', $result['content']);
        $this->assertSame('openai', $result['provider']);
    }

    public function testAnalyzeImageFallsThroughWhenPic2TextModelRowIsMissing(): void
    {
        // PIC2TEXT row exists but the referenced BMODELS row is gone (e.g. catalog
        // reshuffle): provider lookup returns null. We must NOT fail — we should
        // fall back to the capability-level default provider chain.
        $this->modelConfig->expects(self::any())->method('resolveVisionDefault')
            ->with(42)
            ->willReturn([
                'provider' => 'anthropic',
                'model' => null,
                'model_id' => null,
            ]);

        $this->registry->method('getAvailableProviders')
            ->willReturn(['anthropic']);

        $anthropic = $this->mockVisionProvider('anthropic');
        $anthropic->expects($this->once())
            ->method('explainImage')
            ->with(
                'image.jpg',
                'describe',
                $this->callback(function (array $opts): bool {
                    return 'anthropic' === $opts['provider']
                        // No stale PIC2TEXT model string leaks through.
                        && !array_key_exists('model', $opts);
                })
            )
            ->willReturn('a cat (via anthropic)');

        $this->registry->expects(self::any())->method('getVisionProvider')
            ->with('anthropic', $this->anything())
            ->willReturn($anthropic);

        $result = $this->facade->analyzeImage('image.jpg', 'describe', 42);

        $this->assertSame('a cat (via anthropic)', $result['content']);
        $this->assertSame('anthropic', $result['provider']);
    }

    public function testAnalyzeImageRespectsExplicitProviderOverPic2Text(): void
    {
        // When the caller passes an explicit provider, PIC2TEXT must NOT override it.
        // resolveVisionDefault is therefore never consulted.
        $this->modelConfig->expects($this->never())->method('resolveVisionDefault');

        $this->registry->method('getAvailableProviders')
            ->willReturn(['openai']);

        $openai = $this->mockVisionProvider('openai');
        $openai->expects($this->once())
            ->method('explainImage')
            ->with(
                'image.jpg',
                'describe',
                $this->callback(function (array $opts): bool {
                    return 'openai' === $opts['provider']
                        && 'gpt-4o' === $opts['model'];
                })
            )
            ->willReturn('a cat');

        $this->registry->expects(self::any())->method('getVisionProvider')
            ->with('openai', $this->anything())
            ->willReturn($openai);

        $result = $this->facade->analyzeImage(
            'image.jpg',
            'describe',
            42,
            ['provider' => 'openai', 'model' => 'gpt-4o']
        );

        $this->assertSame('openai', $result['provider']);
        $this->assertSame('gpt-4o', $result['model']);
    }

    private function mockVisionProvider(string $name): VisionProviderInterface&MockObject
    {
        $provider = $this->createMock(VisionProviderInterface::class);
        $provider->method('getName')->willReturn($name);

        return $provider;
    }
}
