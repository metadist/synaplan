<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider;

use App\AI\Exception\NoImageException;
use App\AI\Exception\ProviderException;
use App\AI\Provider\OpenAIProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * When the Responses API answers an image edit in prose, the assistant
 * `message` item is the only explanation the user can get (#2406).
 */
final class OpenAIProviderResponsesMessageTextTest extends TestCase
{
    public function testItReadsTheTextOfTheAssistantMessage(): void
    {
        $output = [
            ['type' => 'reasoning', 'summary' => []],
            ['type' => 'message', 'role' => 'assistant', 'content' => [
                ['type' => 'output_text', 'text' => ''],
                ['type' => 'output_text', 'text' => 'Which window should get the grid?'],
            ]],
        ];

        self::assertSame('Which window should get the grid?', $this->messageText($output));
    }

    public function testWithoutAMessageItReturnsNull(): void
    {
        self::assertNull($this->messageText([['type' => 'image_generation_call', 'result' => '']]));
    }

    /**
     * The public generateImage() catch used to replace NoImageException with
     * a context-free ProviderException, so the quoted reply never reached chat.
     */
    public function testPublicGenerateImagePropagatesATextOnlyReply(): void
    {
        $png = tempnam(sys_get_temp_dir(), 'openai-text-reply');
        self::assertNotFalse($png);
        $imagePath = $png.'.png';
        rename($png, $imagePath);
        file_put_contents($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true));

        $client = new MockHttpClient(static function (string $method, string $url): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://api.openai.com/v1/responses', $url);

            return new MockResponse((string) json_encode([
                'output' => [
                    ['type' => 'message', 'role' => 'assistant', 'content' => [
                        ['type' => 'output_text', 'text' => '10'],
                    ]],
                ],
            ], JSON_THROW_ON_ERROR));
        });

        $provider = new OpenAIProvider(new NullLogger(), $client, 'sk-test');

        try {
            $provider->generateImage('How many windows?', [
                'model' => 'gpt-image-1.5',
                'images' => [$imagePath],
            ]);
            self::fail('A text-only reply must not look like a generated image');
        } catch (ProviderException $e) {
            self::assertInstanceOf(NoImageException::class, $e);
            self::assertSame('10', $e->getContext()['text_response'] ?? null);
            self::assertSame('gpt-image-1.5', $e->getContext()['model'] ?? null);
            self::assertStringStartsWith('OpenAI returned text instead of an image', $e->getMessage());
            self::assertStringNotContainsString('OpenAI image generation error', $e->getMessage());
        } finally {
            @unlink($imagePath);
        }
    }

    /**
     * @param array<mixed> $output
     */
    private function messageText(array $output): ?string
    {
        $provider = new OpenAIProvider(new NullLogger(), new MockHttpClient());
        $method = new \ReflectionMethod(OpenAIProvider::class, 'responsesMessageText');

        $text = $method->invoke($provider, $output);

        return is_string($text) ? $text : null;
    }
}
