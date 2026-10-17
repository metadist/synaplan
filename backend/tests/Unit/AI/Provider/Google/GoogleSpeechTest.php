<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider\Google;

use App\AI\Exception\ProviderException;
use App\AI\Provider\Google\GoogleSpeech;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class GoogleSpeechTest extends TestCase
{
    use GoogleApiDouble;

    private string $uploadDir = '';

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/google-speech-'.bin2hex(random_bytes(4));
        mkdir($this->uploadDir);
        file_put_contents($this->uploadDir.'/voice.wav', 'RIFF-not-read-for-a-token-count');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->uploadDir.'/*') ?: []);
        rmdir($this->uploadDir);
    }

    public function testTheTranscriptDurationComesFromTheAudioTokens(): void
    {
        $speech = new GoogleSpeech($this->api([self::json([
            'candidates' => [['content' => ['parts' => [['text' => 'Guten '], ['text' => 'Tag']]]]],
            'usageMetadata' => ['promptTokensDetails' => [['modality' => 'AUDIO', 'tokenCount' => 100]]],
        ])]), new NullLogger(), $this->uploadDir);

        $result = $speech->transcribe('voice.wav', ['language' => 'de']);

        self::assertSame(['text' => 'Guten Tag', 'language' => 'de', 'duration' => 4.0, 'segments' => []], $result);
        self::assertStringEndsWith('/models/gemini-3.5-transcribe:generateContent', $this->requests()[0]['url']);
    }

    public function testRawPcmIsStoredAsAPlayableWav(): void
    {
        $speech = new GoogleSpeech($this->api([self::json([
            'candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'audio/L16;rate=8000;channels=2', 'data' => base64_encode("\x01\x00\x02\x00")]]]]]],
        ])]), new NullLogger(), $this->uploadDir);

        $filename = $speech->synthesize('Hallo');

        $wav = (string) file_get_contents($this->uploadDir.'/'.$filename);
        self::assertMatchesRegularExpression('/^tts_[0-9a-f]{13}\.wav$/', $filename);
        self::assertSame(['RIFF', 'WAVE', 2, 8000, 4], [substr($wav, 0, 4), substr($wav, 8, 4), unpack('v', substr($wav, 22, 2))[1], unpack('V', substr($wav, 24, 4))[1], unpack('V', substr($wav, 40, 4))[1]]);
    }

    public function testThereIsNoTranslateMode(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('no dedicated translate mode');

        (new GoogleSpeech($this->api([]), new NullLogger(), $this->uploadDir))->translateAudio('voice.wav', 'en');
    }
}
