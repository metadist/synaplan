<?php

namespace App\Tests\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Provider\GoogleProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Tests for GoogleProvider's blocked content detection (checkGeminiFinishReason).
 *
 * Gemini may return HTTP 200 but set finishReason or promptFeedback.blockReason
 * when it refuses to generate content. These tests verify that GoogleProvider
 * correctly detects and converts these into ProviderException::contentBlocked.
 */
class GoogleProviderBlockedContentTest extends TestCase
{
    private function createProviderWithMockResponse(array $responseData): GoogleProvider
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn($responseData);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        return new GoogleProvider(
            new NullLogger(),
            $httpClient,
            'fake-api-key',
        );
    }

    public function testSafetyFinishReasonThrowsContentBlocked(): void
    {
        $data = [
            'candidates' => [[
                'finishReason' => 'SAFETY',
                'safetyRatings' => [['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'probability' => 'HIGH']],
                'content' => ['parts' => []],
            ]],
        ];

        $provider = $this->createProviderWithMockResponse($data);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Content blocked by google (SAFETY)');

        $provider->chat([['role' => 'user', 'content' => 'test']], ['model' => 'gemini-1.5-flash']);
    }

    public function testRecitationFinishReasonThrowsContentBlocked(): void
    {
        $data = [
            'candidates' => [[
                'finishReason' => 'RECITATION',
                'content' => ['parts' => [['text' => 'Partial copyrighted text...']]],
            ]],
        ];

        $provider = $this->createProviderWithMockResponse($data);

        try {
            $provider->chat([['role' => 'user', 'content' => 'test']], ['model' => 'gemini-1.5-flash']);
            $this->fail('Expected ProviderException was not thrown');
        } catch (ProviderException $e) {
            $this->assertSame('google', $e->getProviderName());
            $ctx = $e->getContext();
            $this->assertSame('RECITATION', $ctx['block_reason']);
            $this->assertSame('Partial copyrighted text...', $ctx['text_response']);
        }
    }

    public function testProhibitedContentFinishReasonThrowsContentBlocked(): void
    {
        $data = [
            'candidates' => [[
                'finishReason' => 'PROHIBITED_CONTENT',
                'content' => ['parts' => []],
            ]],
        ];

        $provider = $this->createProviderWithMockResponse($data);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('PROHIBITED_CONTENT');

        $provider->chat([['role' => 'user', 'content' => 'test']], ['model' => 'gemini-1.5-flash']);
    }

    public function testPromptFeedbackBlockReasonThrowsContentBlocked(): void
    {
        $data = [
            'promptFeedback' => [
                'blockReason' => 'SAFETY',
                'safetyRatings' => [['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'probability' => 'HIGH']],
            ],
        ];

        $provider = $this->createProviderWithMockResponse($data);

        try {
            $provider->chat([['role' => 'user', 'content' => 'test']], ['model' => 'gemini-1.5-flash']);
            $this->fail('Expected ProviderException was not thrown');
        } catch (ProviderException $e) {
            $this->assertSame('google', $e->getProviderName());
            $ctx = $e->getContext();
            $this->assertSame('SAFETY', $ctx['block_reason']);
            $this->assertNull($ctx['text_response']);
        }
    }

    public function testStopFinishReasonDoesNotThrow(): void
    {
        $data = [
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [['text' => 'Normal response']]],
            ]],
        ];

        $provider = $this->createProviderWithMockResponse($data);

        $response = $provider->chat([['role' => 'user', 'content' => 'test']], ['model' => 'gemini-1.5-flash']);
        $this->assertSame('Normal response', $response['content']);
        $this->assertArrayHasKey('usage', $response);
    }

    public function testMaxTokensFinishReasonDoesNotThrow(): void
    {
        $data = [
            'candidates' => [[
                'finishReason' => 'MAX_TOKENS',
                'content' => ['parts' => [['text' => 'Truncated...']]],
            ]],
        ];

        $provider = $this->createProviderWithMockResponse($data);

        $response = $provider->chat([['role' => 'user', 'content' => 'test']], ['model' => 'gemini-1.5-flash']);
        $this->assertSame('Truncated...', $response['content']);
    }

    public function testNoCandidatesAndNoBlockReasonDoesNotThrow(): void
    {
        $data = [];

        $provider = $this->createProviderWithMockResponse($data);

        $response = $provider->chat([['role' => 'user', 'content' => 'test']], ['model' => 'gemini-1.5-flash']);
        $this->assertSame('', $response['content']);
    }

    public function testNullFinishReasonDoesNotThrow(): void
    {
        $data = [
            'candidates' => [[
                'content' => ['parts' => [['text' => 'Normal response']]],
            ]],
        ];

        $provider = $this->createProviderWithMockResponse($data);

        $response = $provider->chat([['role' => 'user', 'content' => 'test']], ['model' => 'gemini-1.5-flash']);
        $this->assertSame('Normal response', $response['content']);
    }

    public function testATextOnlyImageReplyThrowsWithTheReply(): void
    {
        $provider = $this->createProviderWithMockResponse([
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [
                    ['text' => 'Counting the windows first.', 'thought' => true],
                    ['text' => '10'],
                ]],
            ]],
        ]);

        $e = $this->captureProviderException(fn () => $provider->generateImage(
            'Wie viele Fenster hat dieses Haus?',
            ['model' => 'gemini-3.1-flash-image'],
        ));

        $this->assertSame('google', $e->getProviderName());
        $this->assertSame([
            'text_response' => '10',
            'finish_reason' => 'STOP',
            'model' => 'gemini-3.1-flash-image',
        ], $e->getContext());
        $this->assertSame('Google returned text instead of an image (gemini-3.1-flash-image): "10"', $e->getMessage());
    }

    public function testATextOnlyEditReplyThrowsWithTheReply(): void
    {
        $photo = tempnam(sys_get_temp_dir(), 'gemini-edit-');
        file_put_contents($photo, "\x89PNG\r\n\x1a\n".str_repeat("\0", 32));
        $provider = $this->createProviderWithMockResponse([
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [['text' => '10']]],
            ]],
        ]);

        try {
            $e = $this->captureProviderException(fn () => $provider->generateImage(
                'Wie viele Fenster hat dieses Haus? Antworte nur mit einer Zahl.',
                ['model' => 'gemini-3.1-flash-image', 'images' => [$photo]],
            ));
        } finally {
            @unlink($photo);
        }

        $this->assertSame('10', $e->getContext()['text_response'] ?? null);
        $this->assertSame('STOP', $e->getContext()['finish_reason'] ?? null);
    }

    public function testAStopWithNeitherTextNorImageThrowsWithoutAReply(): void
    {
        $provider = $this->createProviderWithMockResponse([
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => []],
            ]],
        ]);

        $e = $this->captureProviderException(fn () => $provider->generateImage('A lighthouse', ['model' => 'gemini-3.1-flash-image']));

        $this->assertNull($e->getContext()['text_response'] ?? null);
        $this->assertSame('STOP', $e->getContext()['finish_reason'] ?? null);
        $this->assertSame('Google returned no image (gemini-3.1-flash-image, finish reason STOP)', $e->getMessage());
    }

    public function testAnImagenResponseWithoutImageBytesThrows(): void
    {
        $provider = $this->createProviderWithMockResponse([
            'predictions' => [['raiFilteredReason' => 'The image was filtered.']],
        ]);

        $e = $this->captureProviderException(fn () => $provider->generateImage(
            'A lighthouse',
            ['model' => 'imagen-4.0-generate-001', 'modelConfig' => ['api' => 'imagen']],
        ));

        $this->assertSame('google', $e->getProviderName());
        $this->assertSame('The image was filtered.', $e->getContext()['text_response'] ?? null);
        $this->assertSame('imagen-4.0-generate-001', $e->getContext()['model'] ?? null);
    }

    private function captureProviderException(callable $call): ProviderException
    {
        try {
            $call();
        } catch (ProviderException $e) {
            return $e;
        }
        $this->fail('Expected ProviderException was not thrown');
    }

    public function testBlockedResponsePreservesTextResponse(): void
    {
        $longText = str_repeat('A', 500);
        $data = [
            'candidates' => [[
                'finishReason' => 'SAFETY',
                'content' => ['parts' => [['text' => $longText]]],
            ]],
        ];

        $provider = $this->createProviderWithMockResponse($data);

        try {
            $provider->chat([['role' => 'user', 'content' => 'test']], ['model' => 'gemini-1.5-flash']);
            $this->fail('Expected ProviderException was not thrown');
        } catch (ProviderException $e) {
            $ctx = $e->getContext();
            $this->assertSame($longText, $ctx['text_response']);
        }
    }
}
