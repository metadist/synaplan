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
