<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider\OpenAI;

use App\AI\Exception\ProviderException;
use App\AI\Provider\OpenAI\OpenAISpeech;
use App\Tests\Characterization\Support\OpenAIFixtures;
use OpenAI\Responses\Audio\TranscriptionResponse;
use OpenAI\Testing\Enums\OverrideStrategy;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenAISpeechTest extends TestCase
{
    use OpenAIApiDouble;

    private string $uploadDir = '';

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/openai-speech-'.bin2hex(random_bytes(4));
        mkdir($this->uploadDir);
        file_put_contents($this->uploadDir.'/voice.mp3', 'ID3-voice');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->uploadDir.'/*') ?: []);
        rmdir($this->uploadDir);
    }

    public function testATranscriptionSendsTheFileWithItsLanguage(): void
    {
        $speech = new OpenAISpeech($this->api([OpenAIFixtures::json(TranscriptionResponse::fake(['task' => 'transcribe', 'language' => 'german', 'duration' => 1.5, 'text' => 'Hallo', 'segments' => []], null, OverrideStrategy::Replace)->toArray())]), new NullLogger(), $this->uploadDir);

        self::assertSame(['text' => 'Hallo', 'language' => 'german', 'duration' => 1.5, 'segments' => []], $speech->transcribe('voice.mp3', ['language' => 'de']));
        $fields = $this->sdk->requests()[0]['body'];
        self::assertSame(['whisper-1', 'de', 'verbose_json', 'voice.mp3'], [$fields['model'], $fields['language'], $fields['response_format'], $fields['file']['filename']]);
    }

    public function testTheSynthesisStreamArrivesAsItIsSent(): void
    {
        $speech = new OpenAISpeech($this->api(httpResponses: [new MockResponse(['ID3-', 'audio'])]), new NullLogger(), $this->uploadDir);

        self::assertSame(['ID3-', 'audio'], iterator_to_array($speech->synthesizeStream('Hallo', ['voice' => 'nova']), false));
        self::assertSame(['model' => 'tts-1', 'voice' => 'nova', 'input' => 'Hallo', 'response_format' => 'mp3', 'speed' => 1.0], $this->http->requests()[0]['body']);
    }

    public function testAFailedStreamSaysWhatTheServerAnswered(): void
    {
        $speech = new OpenAISpeech($this->api(httpResponses: [new MockResponse('{"error":"quota"}', ['http_code' => 429])]), new NullLogger(), $this->uploadDir);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('OpenAI TTS stream HTTP 429: {"error":"quota"}');

        iterator_to_array($speech->synthesizeStream('Hallo'), false);
    }
}
