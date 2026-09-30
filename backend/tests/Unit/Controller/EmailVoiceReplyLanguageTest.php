<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\AI\Provider\PiperProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Email voice reply (#2283): a German classification language must reach Piper
 * as `de` and select a German voice — not the English default.
 *
 * WebhookController passes `$result['classification']['language']` into
 * AiFacade::synthesize(); the facade forwards it in provider options.
 */
final class EmailVoiceReplyLanguageTest extends TestCase
{
    public function testGermanReplyLanguageSelectsGermanPiperVoice(): void
    {
        $base = sys_get_temp_dir().'/syn_email_tts_'.uniqid();
        $provider = new PiperProvider(
            $this->createMock(HttpClientInterface::class),
            'http://tts:10200',
            new NullLogger(),
            new Filesystem(),
            $base.'/temp',
            $base.'/uploads',
        );

        $method = new \ReflectionMethod($provider, 'resolveVoice');
        $voice = (string) $method->invoke($provider, [
            'language' => 'de',
            'model' => 'en_US-lessac-medium',
        ]);

        self::assertSame('de_DE-kerstin-low', $voice);
        (new Filesystem())->remove($base);
    }

    public function testWebhookControllerPassesClassificationLanguageToSynthesize(): void
    {
        $src = file_get_contents(\dirname(__DIR__, 3).'/src/Controller/WebhookController.php');
        self::assertIsString($src);
        self::assertStringContainsString("\$result['classification']['language']", $src);
        self::assertMatchesRegularExpression(
            '/synthesize\(\$responseText,\s*\$ttsLanguage/',
            $src,
        );
    }

    public function testStreamControllerPassesLanguageAsRequiredArgument(): void
    {
        $src = file_get_contents(\dirname(__DIR__, 3).'/src/Controller/StreamController.php');
        self::assertIsString($src);
        self::assertMatchesRegularExpression(
            '/synthesize\(\$responseText,\s*\$language/',
            $src,
        );
        self::assertStringNotContainsString('TtsTextSanitizer::prepareForSynthesis', $src);
    }
}
