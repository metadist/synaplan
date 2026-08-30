<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use App\AI\Provider\PiperProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Regression for issue #490 (Piper multi-language mispronunciation).
 *
 * Verifies the voice-selection precedence in {@see PiperProvider::resolveVoice()}:
 * an explicit voice wins, then the message language, then the user's configured
 * voice model, and only English as a last resort — so German text is never
 * silently spoken with the English default voice.
 */
class PiperProviderResolveVoiceTest extends TestCase
{
    private string $tempDir;
    private string $uploadDir;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir().'/syn_piper_test_'.uniqid();
        $this->tempDir = $base.'/temp';
        $this->uploadDir = $base.'/uploads';
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(\dirname($this->tempDir));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveVoice(array $options): string
    {
        $provider = new PiperProvider(
            $this->createMock(HttpClientInterface::class),
            'http://tts:10200',
            new NullLogger(),
            new Filesystem(),
            $this->tempDir,
            $this->uploadDir,
        );

        $method = new \ReflectionMethod($provider, 'resolveVoice');

        return (string) $method->invoke($provider, $options);
    }

    public function testExplicitVoiceAlwaysWins(): void
    {
        self::assertSame(
            'en_US-lessac-medium',
            $this->resolveVoice([
                'voice' => 'en_US-lessac-medium',
                'language' => 'de',
                'model' => 'de_DE-thorsten-medium',
            ]),
            'An explicit per-request voice must override language and model.'
        );
    }

    public function testGermanLanguageOverridesEnglishConfiguredModel(): void
    {
        // The core bug: a German reply must be spoken with a German voice even
        // when the configured TEXT2SOUND default model is an English voice.
        self::assertSame(
            'de_DE-kerstin-low',
            $this->resolveVoice([
                'language' => 'de',
                'model' => 'en_US-lessac-medium',
            ])
        );
    }

    public function testLocaleCodeIsNormalizedToShortForm(): void
    {
        self::assertSame('de_DE-kerstin-low', $this->resolveVoice(['language' => 'de_DE']));
        self::assertSame('de_DE-kerstin-low', $this->resolveVoice(['language' => 'de-DE']));
    }

    public function testConfiguredModelMatchingLanguageIsPreserved(): void
    {
        // A user-picked German voice must not be flattened to the generic
        // language default when it already targets the requested language.
        self::assertSame(
            'de_DE-thorsten-medium',
            $this->resolveVoice([
                'language' => 'de',
                'model' => 'de_DE-thorsten-medium',
            ])
        );
    }

    public function testConfiguredModelUsedWhenLanguageUnknown(): void
    {
        // No usable language: honour the configured voice model instead of
        // silently defaulting to English.
        self::assertSame(
            'de_DE-thorsten-medium',
            $this->resolveVoice([
                'language' => '',
                'model' => 'de_DE-thorsten-medium',
            ])
        );
        self::assertSame(
            'de_DE-thorsten-medium',
            $this->resolveVoice(['model' => 'de_DE-thorsten-medium'])
        );
    }

    public function testUnmappedLanguageFallsBackToConfiguredModel(): void
    {
        self::assertSame(
            'de_DE-thorsten-medium',
            $this->resolveVoice([
                'language' => 'it',
                'model' => 'de_DE-thorsten-medium',
            ])
        );
    }

    public function testEnglishDefaultOnlyAsLastResort(): void
    {
        self::assertSame('en_US-lessac-medium', $this->resolveVoice([]));
        self::assertSame('en_US-lessac-medium', $this->resolveVoice(['language' => 'xx']));
    }
}
