<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Service;

use App\AI\Interface\SpeechToTextProviderInterface;
use App\AI\Service\AiFacade;
use App\AI\Service\ProviderRegistry;
use App\Service\CircuitBreaker;
use App\Service\DiscordNotificationService;
use App\Service\File\UserUploadPathBuilder;
use App\Service\InternalEmailService;
use App\Service\ModelConfigService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Regression tests for AiFacade::transcribe() provider/model selection.
 *
 * Covers issues #696 and #700: prior to the fix the SOUND2TEXT default model
 * row was only consulted as a boolean ("use external STT yes/no"). The actual
 * provider was resolved from the legacy ai/default_speech_to_text_provider
 * config which the settings UI never writes — so users who picked
 * "Groq / whisper-large-v3" silently ended up on OpenAI / whisper-1 (#696),
 * and even when the right provider was reached, the specific model within a
 * provider (e.g. whisper-large-v3 vs whisper-large-v3-turbo) was never
 * forwarded, so the provider's hardcoded default was used regardless (#700).
 *
 * Mirrors AiFacadeAnalyzeImageTest, which guards the analogous PIC2TEXT fix.
 */
class AiFacadeTranscribeTest extends TestCase
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
            '/tmp'
        );
    }

    public function testTranscribeHonoursSound2TextProviderAndModel(): void
    {
        // User picked Groq / whisper-large-v3 in Settings → Models.
        // The facade must call Groq with that exact model id, not OpenAI/whisper-1.
        $this->modelConfig->method('resolveSttDefault')
            ->with(42)
            ->willReturn([
                'provider' => 'groq',
                'model' => 'whisper-large-v3',
                'model_id' => 21,
            ]);

        $groq = $this->mockSttProvider('groq');
        $groq->expects($this->once())
            ->method('transcribe')
            ->with(
                'audio.mp3',
                $this->callback(function (array $opts): bool {
                    return 'whisper-large-v3' === $opts['model'];
                })
            )
            ->willReturn([
                'text' => 'hello',
                'language' => 'en',
                'duration' => 1.0,
                'segments' => [],
            ]);

        $this->registry->method('getSpeechToTextProvider')
            ->with('groq')
            ->willReturn($groq);

        $result = $this->facade->transcribe('audio.mp3', 42);

        $this->assertSame('hello', $result['text']);
        $this->assertSame('groq', $result['provider']);
        $this->assertSame('whisper-large-v3', $result['model']);
    }

    public function testTranscribeForwardsNonTurboModelWhenSameProviderHasMultiple(): void
    {
        // Regression for issue #700: when a single provider exposes multiple
        // STT models (e.g. Groq exposes both whisper-large-v3 and
        // whisper-large-v3-turbo) the user's specific pick MUST reach the
        // provider — not the provider's hardcoded default (turbo). Prior to
        // the fix, GroqProvider's "$options['model'] ?? 'whisper-large-v3-turbo'"
        // fallback masked the user's choice because the facade never set the
        // model in $options.
        $this->modelConfig->method('resolveSttDefault')
            ->with(42)
            ->willReturn([
                'provider' => 'groq',
                'model' => 'whisper-large-v3',
                'model_id' => 21,
            ]);

        $groq = $this->mockSttProvider('groq');
        $groq->expects($this->once())
            ->method('transcribe')
            ->with(
                'audio.mp3',
                $this->callback(static function (array $opts): bool {
                    // The non-turbo model must be forwarded verbatim — never
                    // collapsed to the provider's "-turbo" hardcoded default.
                    return array_key_exists('model', $opts)
                        && 'whisper-large-v3' === $opts['model'];
                })
            )
            ->willReturn([
                'text' => 'accurate transcription',
                'language' => 'en',
                'duration' => 2.5,
                'segments' => [],
            ]);

        $this->registry->method('getSpeechToTextProvider')
            ->with('groq')
            ->willReturn($groq);

        $result = $this->facade->transcribe('audio.mp3', 42);

        $this->assertSame('groq', $result['provider']);
        $this->assertSame('whisper-large-v3', $result['model']);
    }

    public function testTranscribeRespectsExplicitProviderOverSound2Text(): void
    {
        // When the caller passes an explicit provider, SOUND2TEXT must NOT
        // override it. resolveSttDefault is therefore never consulted.
        $this->modelConfig->expects($this->never())->method('resolveSttDefault');

        $openai = $this->mockSttProvider('openai');
        $openai->expects($this->once())
            ->method('transcribe')
            ->with(
                'audio.mp3',
                $this->callback(function (array $opts): bool {
                    return 'openai' === $opts['provider']
                        && 'whisper-1' === $opts['model'];
                })
            )
            ->willReturn([
                'text' => 'hi',
                'language' => 'en',
                'duration' => 0.5,
                'segments' => [],
            ]);

        $this->registry->method('getSpeechToTextProvider')
            ->with('openai')
            ->willReturn($openai);

        $result = $this->facade->transcribe(
            'audio.mp3',
            42,
            ['provider' => 'openai', 'model' => 'whisper-1']
        );

        $this->assertSame('openai', $result['provider']);
        $this->assertSame('whisper-1', $result['model']);
    }

    public function testTranscribeFallsThroughWhenSound2TextModelRowIsMissing(): void
    {
        // SOUND2TEXT row exists but the referenced BMODELS row is gone (e.g.
        // catalog reshuffle): resolveSttDefault returns the legacy capability
        // provider with model_id=null. We must NOT leak a stale model string —
        // the provider should fall back to its internal default.
        $this->modelConfig->method('resolveSttDefault')
            ->with(42)
            ->willReturn([
                'provider' => 'openai',
                'model' => null,
                'model_id' => null,
            ]);

        $openai = $this->mockSttProvider('openai');
        $openai->expects($this->once())
            ->method('transcribe')
            ->with(
                'audio.mp3',
                $this->callback(function (array $opts): bool {
                    return !array_key_exists('model', $opts);
                })
            )
            ->willReturn([
                'text' => 'fallback',
                'language' => 'en',
                'duration' => 1.0,
                'segments' => [],
            ]);

        $this->registry->method('getSpeechToTextProvider')
            ->with('openai')
            ->willReturn($openai);

        $result = $this->facade->transcribe('audio.mp3', 42);

        $this->assertSame('openai', $result['provider']);
        $this->assertSame('unknown', $result['model']);
    }

    public function testTranscribeRespectsCallerSuppliedModelEvenWhenSound2TextSet(): void
    {
        // Caller explicitly provided a model but no provider: keep the caller's
        // model and resolve provider/model owner from SOUND2TEXT.
        $this->modelConfig->method('resolveSttDefault')
            ->with(42)
            ->willReturn([
                'provider' => 'groq',
                'model' => 'whisper-large-v3',
                'model_id' => 21,
            ]);

        $groq = $this->mockSttProvider('groq');
        $groq->expects($this->once())
            ->method('transcribe')
            ->with(
                'audio.mp3',
                $this->callback(function (array $opts): bool {
                    return 'distil-whisper-large-v3-en' === $opts['model'];
                })
            )
            ->willReturn([
                'text' => 'hi',
                'language' => 'en',
                'duration' => 1.0,
                'segments' => [],
            ]);

        $this->registry->method('getSpeechToTextProvider')
            ->with('groq')
            ->willReturn($groq);

        $result = $this->facade->transcribe(
            'audio.mp3',
            42,
            ['model' => 'distil-whisper-large-v3-en']
        );

        $this->assertSame('groq', $result['provider']);
        $this->assertSame('distil-whisper-large-v3-en', $result['model']);
    }

    public function testTranscribeWithoutUserIdSkipsSound2TextLookup(): void
    {
        // Anonymous / system call (e.g. WhatsApp pre-verification flow): no
        // user-specific config exists, so resolveSttDefault must not be called.
        $this->modelConfig->expects($this->never())->method('resolveSttDefault');

        $provider = $this->mockSttProvider('test');
        $provider->expects($this->once())
            ->method('transcribe')
            ->willReturn([
                'text' => 'sys',
                'language' => 'en',
                'duration' => 0.1,
                'segments' => [],
            ]);

        $this->registry->method('getSpeechToTextProvider')
            ->with(null)
            ->willReturn($provider);

        $result = $this->facade->transcribe('audio.mp3');

        $this->assertSame('test', $result['provider']);
    }

    private function mockSttProvider(string $name): SpeechToTextProviderInterface&MockObject
    {
        $provider = $this->createMock(SpeechToTextProviderInterface::class);
        $provider->method('getName')->willReturn($name);

        return $provider;
    }
}
