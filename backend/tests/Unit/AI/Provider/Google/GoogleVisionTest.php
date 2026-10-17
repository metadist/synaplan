<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider\Google;

use App\AI\Exception\ProviderException;
use App\AI\Provider\Google\GoogleVision;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class GoogleVisionTest extends TestCase
{
    use GoogleApiDouble;

    private string $uploadDir = '';

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/google-vision-'.bin2hex(random_bytes(4));
        mkdir($this->uploadDir);
        file_put_contents($this->uploadDir.'/photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACklEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg=='));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->uploadDir.'/*') ?: []);
        rmdir($this->uploadDir);
    }

    public function testTheImageIsSentInlineWithTheDefaultPrompt(): void
    {
        $vision = new GoogleVision($this->api([self::json(['candidates' => [['content' => ['parts' => [['text' => 'A pixel.']]]]]])]), new NullLogger(), $this->uploadDir);

        self::assertSame('A pixel.', $vision->explainImage('photo.png'));

        $request = $this->requests()[0];
        self::assertStringEndsWith('/models/gemini-2.5-pro:generateContent', $request['url']);
        self::assertSame('Describe what you see in this image in detail.', $request['body']['contents'][0]['parts'][0]['text']);
        self::assertSame(['mime_type' => 'image/png', 'data' => base64_encode((string) file_get_contents($this->uploadDir.'/photo.png'))], $request['body']['contents'][0]['parts'][1]['inline_data']);
        self::assertSame(8192, $request['body']['generationConfig']['maxOutputTokens']);
    }

    public function testAMissingImageIsAProviderErrorWithoutARequest(): void
    {
        $vision = new GoogleVision($this->api([]), new NullLogger(), $this->uploadDir);

        try {
            $vision->analyzeImage('missing.png', 'What is this?');
            self::fail('A missing image must be refused.');
        } catch (ProviderException $e) {
            self::assertSame('Google vision error: Image file not found: '.$this->uploadDir.'/missing.png', $e->getMessage());
        }
        self::assertSame([], $this->requests());
    }
}
