<?php

namespace App\Tests\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Provider\GoogleProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Tests for GoogleProvider's blocked content detection (checkGeminiFinishReason).
 *
 * Gemini may return HTTP 200 but set finishReason or promptFeedback.blockReason
 * when it refuses to generate content. These tests verify that GoogleProvider
 * correctly detects and converts these into ProviderException::contentBlocked.
 */
class GoogleProviderBlockedContentTest extends TestCase
{
    private GoogleProvider $provider;

    protected function setUp(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $this->provider = new GoogleProvider(
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

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Content blocked by google (SAFETY)');

        $this->invokeCheckGeminiFinishReason($data);
    }

    public function testRecitationFinishReasonThrowsContentBlocked(): void
    {
        $data = [
            'candidates' => [[
                'finishReason' => 'RECITATION',
                'content' => ['parts' => [['text' => 'Partial copyrighted text...']]],
            ]],
        ];

        try {
            $this->invokeCheckGeminiFinishReason($data);
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

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('PROHIBITED_CONTENT');

        $this->invokeCheckGeminiFinishReason($data);
    }

    public function testPromptFeedbackBlockReasonThrowsContentBlocked(): void
    {
        $data = [
            'promptFeedback' => [
                'blockReason' => 'SAFETY',
                'safetyRatings' => [['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'probability' => 'HIGH']],
            ],
        ];

        try {
            $this->invokeCheckGeminiFinishReason($data);
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

        $this->invokeCheckGeminiFinishReason($data);
        $this->addToAssertionCount(1);
    }

    public function testMaxTokensFinishReasonDoesNotThrow(): void
    {
        $data = [
            'candidates' => [[
                'finishReason' => 'MAX_TOKENS',
                'content' => ['parts' => [['text' => 'Truncated...']]],
            ]],
        ];

        $this->invokeCheckGeminiFinishReason($data);
        $this->addToAssertionCount(1);
    }

    public function testNoCandidatesAndNoBlockReasonDoesNotThrow(): void
    {
        $data = [];

        $this->invokeCheckGeminiFinishReason($data);
        $this->addToAssertionCount(1);
    }

    public function testNullFinishReasonDoesNotThrow(): void
    {
        $data = [
            'candidates' => [[
                'content' => ['parts' => [['text' => 'Normal response']]],
            ]],
        ];

        $this->invokeCheckGeminiFinishReason($data);
        $this->addToAssertionCount(1);
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

        try {
            $this->invokeCheckGeminiFinishReason($data);
            $this->fail('Expected ProviderException was not thrown');
        } catch (ProviderException $e) {
            $ctx = $e->getContext();
            $this->assertSame($longText, $ctx['text_response']);
        }
    }

    /**
     * Invoke the private checkGeminiFinishReason method via reflection.
     */
    private function invokeCheckGeminiFinishReason(array $data): void
    {
        $reflection = new \ReflectionMethod(GoogleProvider::class, 'checkGeminiFinishReason');
        $reflection->invoke($this->provider, $data);
    }
}
