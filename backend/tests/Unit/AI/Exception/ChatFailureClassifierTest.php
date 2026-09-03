<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Exception;

use App\AI\Exception\ChatFailureClassifier;
use App\AI\Exception\ChatFailureReason;
use App\AI\Exception\ProviderException;
use OpenAI\Exceptions\RateLimitException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;

final class ChatFailureClassifierTest extends TestCase
{
    private ChatFailureClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new ChatFailureClassifier();
    }

    public function testGroqSchemaMismatchFromStructuredContext(): void
    {
        $error = new ProviderException(
            'Groq chat error: Generated JSON does not match the expected schema.',
            'groq',
            [
                'error_type' => 'invalid_request_error',
                'error_code' => 'json_validate_failed',
                'status_code' => 400,
                'stage' => 'chat',
            ],
            400,
        );

        self::assertSame(ChatFailureReason::SchemaMismatch, $this->classifier->classify($error));
        self::assertTrue(ChatFailureReason::SchemaMismatch->suggestsOtherModel());
    }

    #[DataProvider('contextProvider')]
    public function testStructuredContextMapsToReason(ProviderException $error, ChatFailureReason $expected): void
    {
        self::assertSame($expected, $this->classifier->classify($error));
    }

    /**
     * @return iterable<string, array{ProviderException, ChatFailureReason}>
     */
    public static function contextProvider(): iterable
    {
        yield 'content blocked' => [
            ProviderException::contentBlocked('google', 'SAFETY'),
            ChatFailureReason::ContentFiltered,
        ];
        yield 'missing api key' => [
            ProviderException::missingApiKey('groq', 'GROQ_API_KEY'),
            ChatFailureReason::AuthFailed,
        ];
        yield 'model not available' => [
            ProviderException::noModelAvailable('chat', 'ollama', 'qwen2.5:3b'),
            ChatFailureReason::ModelUnavailable,
        ];
        yield '401 status' => [
            new ProviderException('denied', 'openai', ['status_code' => 401], 401),
            ChatFailureReason::AuthFailed,
        ];
        yield '402 quota' => [
            new ProviderException('pay', 'openai', ['error_code' => 'insufficient_quota'], 402),
            ChatFailureReason::QuotaExceeded,
        ];
        yield '404 model' => [
            new ProviderException('gone', 'openai', ['error_code' => 'model_not_found'], 404),
            ChatFailureReason::ModelUnavailable,
        ];
        yield '413 too large' => [
            new ProviderException('big', 'openai', null, 413),
            ChatFailureReason::RequestTooLarge,
        ];
        yield '429 rate limit' => [
            new ProviderException('slow', 'openai', null, 429),
            ChatFailureReason::RateLimited,
        ];
        yield '500 upstream' => [
            new ProviderException('down', 'openai', null, 500),
            ChatFailureReason::UpstreamUnavailable,
        ];
        yield 'context length code' => [
            new ProviderException('long', 'openai', ['error_code' => 'context_length_exceeded'], 400),
            ChatFailureReason::ContextLengthExceeded,
        ];
        yield 'content filter code' => [
            new ProviderException('blocked', 'openai', ['error_code' => 'content_filter'], 400),
            ChatFailureReason::ContentFiltered,
        ];
    }

    public function testOpenAiRateLimitExceptionClass(): void
    {
        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);

        self::assertSame(
            ChatFailureReason::RateLimited,
            $this->classifier->classify(new RateLimitException($response))
        );
    }

    public function testTimeoutExceptionInterface(): void
    {
        $timeout = new class('Idle timeout reached') extends \RuntimeException implements TimeoutExceptionInterface {
        };

        self::assertSame(
            ChatFailureReason::Timeout,
            $this->classifier->classify($timeout)
        );
    }

    public function testUnknownFailureDoesNotGuessFromFreeText(): void
    {
        $error = new ProviderException(
            'Groq chat error: Generated JSON does not match the expected schema. additionalProperties BDATETIME not allowed',
            'groq',
        );

        self::assertSame(ChatFailureReason::Unknown, $this->classifier->classify($error));
    }

    public function testWrappedSdkErrorIsReadFromPrevious(): void
    {
        $response = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(400);
        $previous = new \OpenAI\Exceptions\ErrorException(
            ['message' => 'schema failed', 'type' => 'invalid_request_error', 'code' => 'json_validate_failed'],
            $response,
        );

        $wrapped = new ProviderException('Groq chat error: schema failed', 'groq', null, 0, $previous);

        self::assertSame(ChatFailureReason::SchemaMismatch, $this->classifier->classify($wrapped));
    }
}
