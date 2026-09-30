<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Service;

use App\AI\Credential\HiggsfieldCredentialResolver;
use App\AI\Exception\NoSpeakableTextException;
use App\AI\Health\ModelHealthRecorder;
use App\AI\Interface\TextToSpeechProviderInterface;
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
 * Regression for issue #2283: AiFacade sanitizes spoken text and requires language.
 */
final class AiFacadeSynthesizeTest extends TestCase
{
    private ProviderRegistry&MockObject $registry;
    private ModelConfigService&MockObject $modelConfig;
    private CircuitBreaker&MockObject $circuitBreaker;
    private AiFacade $facade;
    private string $uploadDir;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(ProviderRegistry::class);
        $this->modelConfig = $this->createMock(ModelConfigService::class);
        $this->circuitBreaker = $this->createMock(CircuitBreaker::class);
        $this->circuitBreaker->method('execute')
            ->willReturnCallback(fn (callable $cb) => $cb());

        $this->uploadDir = sys_get_temp_dir().'/syn_tts_facade_'.uniqid('', true);
        mkdir($this->uploadDir, 0777, true);

        $this->facade = new AiFacade(
            $this->registry,
            $this->modelConfig,
            $this->circuitBreaker,
            new NullLogger(),
            $this->createMock(UserUploadPathBuilder::class),
            $this->createMock(DiscordNotificationService::class),
            $this->createMock(InternalEmailService::class),
            $this->createMock(CacheInterface::class),
            $this->createMock(CacheItemPoolInterface::class),
            $this->createMock(HiggsfieldCredentialResolver::class),
            $this->createMock(TranscriptionUsageRecorder::class),
            $this->createMock(ModelHealthRecorder::class),
            $this->uploadDir,
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->uploadDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->uploadDir);
    }

    public function testSynthesizeSanitizesMarkupAndForwardsLanguage(): void
    {
        $provider = $this->createMock(TextToSpeechProviderInterface::class);
        $provider->method('getName')->willReturn('piper');
        $provider->method('getDefaultModels')->willReturn(['text2sound' => 'en_US-lessac-medium']);
        $provider->expects(self::once())
            ->method('synthesize')
            ->with(
                self::callback(static function (string $text): bool {
                    self::assertStringNotContainsString('<think>', $text);
                    self::assertStringNotContainsString('[Memory:1]', $text);
                    self::assertStringNotContainsString('**', $text);
                    self::assertStringContainsString('Hallo Welt', $text);

                    return true;
                }),
                self::callback(static function (array $opts): bool {
                    return 'de' === ($opts['language'] ?? null);
                }),
            )
            ->willReturnCallback(function (): string {
                $filename = 'tts_'.uniqid('', true).'.mp3';
                file_put_contents($this->uploadDir.'/'.$filename, 'audio');

                return $filename;
            });

        $this->registry->expects(self::once())
            ->method('getTextToSpeechProvider')
            ->willReturn($provider);

        $result = $this->facade->synthesize(
            "<think>secret</think>\n**Hallo** Welt [Memory:1]",
            'de',
            null,
            ['provider' => 'piper'],
        );

        self::assertSame('piper', $result['provider']);
        self::assertArrayHasKey('relativePath', $result);
    }

    public function testSynthesizeRejectsEmptyLanguage(): void
    {
        $this->registry->expects(self::never())->method('getTextToSpeechProvider');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TTS language is required');

        $this->facade->synthesize('Hello', '  ', null, ['provider' => 'piper']);
    }

    public function testSynthesizeRejectsThinkOnlyTextWithoutCallingProvider(): void
    {
        $provider = $this->createMock(TextToSpeechProviderInterface::class);
        $provider->method('getName')->willReturn('piper');
        $provider->expects(self::never())->method('synthesize');

        $this->registry->expects(self::once())
            ->method('getTextToSpeechProvider')
            ->willReturn($provider);

        $this->expectException(NoSpeakableTextException::class);

        $this->facade->synthesize(
            '<think>internal reasoning only</think>',
            'de',
            null,
            ['provider' => 'piper'],
        );
    }

    public function testSynthesizeStreamRejectsCodeOnlyTextWithoutCallingProvider(): void
    {
        $provider = $this->createMock(TextToSpeechProviderInterface::class);
        $provider->method('getName')->willReturn('piper');
        $provider->expects(self::never())->method('synthesizeStream');

        $this->registry->expects(self::once())
            ->method('getTextToSpeechProvider')
            ->willReturn($provider);

        $this->expectException(NoSpeakableTextException::class);

        $this->facade->synthesizeStream(
            "```\nconsole.log('hi');\n```",
            'en',
            null,
            ['provider' => 'piper'],
        );
    }

    public function testSynthesizeStreamSanitizesAndForwardsLanguage(): void
    {
        $provider = $this->createMock(TextToSpeechProviderInterface::class);
        $provider->method('getName')->willReturn('piper');
        $provider->method('supportsStreaming')->willReturn(true);
        $provider->method('getStreamContentType')->willReturn('audio/webm');
        $provider->expects(self::once())
            ->method('synthesizeStream')
            ->with(
                'Clean sentence',
                self::callback(static function (array $opts): bool {
                    return 'fr' === ($opts['language'] ?? null);
                }),
            )
            ->willReturn((static function (): \Generator {
                yield 'chunk';
            })());

        $this->registry->expects(self::once())
            ->method('getTextToSpeechProvider')
            ->willReturn($provider);

        $result = $this->facade->synthesizeStream(
            "```code```\nClean sentence",
            'fr',
            null,
            ['provider' => 'piper'],
        );

        self::assertSame('piper', $result['provider']);
        self::assertSame(['chunk'], iterator_to_array($result['generator']));
    }
}
