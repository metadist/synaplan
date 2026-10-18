<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider\Google;

use App\AI\Exception\NoImageException;
use App\AI\Provider\Google\GoogleImageGeneration;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class GoogleImageGenerationTest extends TestCase
{
    use GoogleApiDouble;

    private const PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACklEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg==';

    public function testAGeminiImageComesBackAsADataUrl(): void
    {
        $images = new GoogleImageGeneration($this->api([self::json(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => self::PIXEL]]]], 'finishReason' => 'STOP']]])]), new NullLogger(), sys_get_temp_dir());

        $result = $images->generateImage('A red bicycle');

        self::assertSame([['url' => 'data:image/png;base64,'.self::PIXEL, 'revised_prompt' => 'A red bicycle']], $result);
        $request = $this->requests()[0];
        self::assertStringEndsWith('/models/gemini-3.1-flash-image:generateContent', $request['url']);
        self::assertSame(['TEXT', 'IMAGE'], $request['body']['generationConfig']['responseModalities']);
    }

    public function testImagenGoesToVertexWithItsOwnToken(): void
    {
        $images = new GoogleImageGeneration($this->api([self::json(['predictions' => [['bytesBase64Encoded' => self::PIXEL]]])], 'synaplan', self::VERTEX_TOKEN), new NullLogger(), sys_get_temp_dir());

        $images->generateImage('A lighthouse', ['model' => 'imagen-4.0-generate-001']);

        $request = $this->requests()[0];
        self::assertStringStartsWith('https://us-central1-aiplatform.googleapis.com/v1/projects/synaplan/', $request['url']);
        self::assertContains('Authorization: Bearer <secret>', $request['headers']);
        self::assertNotContains('x-goog-api-key: <secret>', $request['headers']);
    }

    public function testAnAnswerInProseIsANoImageExceptionThatKeepsTheText(): void
    {
        $images = new GoogleImageGeneration($this->api([self::json(['candidates' => [['content' => ['parts' => [['text' => 'It is a cat.']]], 'finishReason' => 'STOP']]])]), new NullLogger(), sys_get_temp_dir());

        try {
            $images->generateImage('What is in the photo?', ['model' => 'gemini-2.5-flash-image']);
            self::fail('An answer without an image must be refused.');
        } catch (NoImageException $e) {
            self::assertStringContainsString('It is a cat.', $e->getMessage());
        }
    }
}
