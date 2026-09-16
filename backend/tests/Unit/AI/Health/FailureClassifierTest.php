<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Health;

use App\AI\Exception\ProviderCancelledException;
use App\AI\Exception\ProviderException;
use App\AI\Exception\StructuredOutputViolationException;
use App\AI\Health\FailureClassifier;
use App\AI\Health\FailureKind;
use App\Service\Exception\StreamCancelledException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FailureClassifierTest extends TestCase
{
    private FailureClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new FailureClassifier();
    }

    /**
     * A rate limit must never be able to retire a model. This is the single
     * most important rule in the whole health monitor: without it, the first
     * traffic spike switches off the busiest model.
     */
    public function testRateLimitIsTransientAndNeverJustifiesAutoDisable(): void
    {
        $kind = $this->classifier->classify(
            new ProviderException('Rate limit reached for gpt-5', 'openai', null, 429)
        );

        self::assertSame(FailureKind::Transient, $kind);
        self::assertFalse($kind->justifiesAutoDisable());
        self::assertTrue($kind->countsAgainstModel());
    }

    public function testRateLimitWithoutStatusCodeIsStillTransient(): void
    {
        // Several SDKs wrap the upstream response and lose the status.
        $kind = $this->classifier->classify(
            new ProviderException('Groq chat error: Too Many Requests', 'groq')
        );

        self::assertSame(FailureKind::Transient, $kind);
    }

    /**
     * A schema-rejected generation is a prompt/schema problem on our side,
     * not model health. Its message carries no recognised pattern and its
     * status is 400, which would otherwise be filed by status alone — the
     * type must decide before any text or status matching.
     */
    public function testSchemaViolationIsAUserErrorThatDoesNotCountAgainstTheModel(): void
    {
        $kind = $this->classifier->classify(new StructuredOutputViolationException(
            'groq',
            "jsonschema: '' does not validate with /additionalProperties: additionalProperties 'BTEXT' not allowed",
            '{"BTEXT":"hi","BTOPIC":"general"}',
            'sort_classification',
        ));

        self::assertSame(FailureKind::UserError, $kind);
        self::assertFalse($kind->countsAgainstModel());
        self::assertFalse($kind->justifiesAutoDisable());
    }

    public function testMissingApiKeyIsCredential(): void
    {
        $kind = $this->classifier->classify(
            ProviderException::missingApiKey('anthropic', 'ANTHROPIC_API_KEY')
        );

        self::assertSame(FailureKind::Credential, $kind);
        self::assertTrue($kind->isProviderWide());
        self::assertFalse($kind->countsAgainstModel());
    }

    public function testBlockedContentIsAUserErrorNotAModelDefect(): void
    {
        $kind = $this->classifier->classify(
            ProviderException::contentBlocked('google', 'SAFETY')
        );

        self::assertSame(FailureKind::UserError, $kind);
        self::assertFalse($kind->countsAgainstModel());
    }

    /**
     * The message puts the model name between the words ("Model 'x' not found
     * for provider 'y'"), which a naive "model not found" match would miss.
     */
    public function testRejectedModelNameIsPermanent(): void
    {
        $kind = $this->classifier->classify(
            ProviderException::noModelAvailable('chat', 'openai', 'gpt-4-vision-preview')
        );

        self::assertSame(FailureKind::Permanent, $kind);
        self::assertTrue($kind->justifiesAutoDisable());
    }

    public function testOllamaMissingModelIsPermanent(): void
    {
        $kind = $this->classifier->classify(
            ProviderException::noModelAvailable('chat', 'ollama', 'qwen2.5:3b')
        );

        self::assertSame(FailureKind::Permanent, $kind);
    }

    public function testUserCancelIsNeverCountedAsAFailure(): void
    {
        foreach ([new ProviderCancelledException('stopped'), new StreamCancelledException('stopped')] as $error) {
            $kind = $this->classifier->classify($error);

            self::assertSame(FailureKind::Cancelled, $kind);
            self::assertFalse($kind->countsAgainstModel());
        }
    }

    /**
     * An open circuit is an echo of earlier failures, not new evidence. It stays
     * transient so a provider hiccup can never escalate into a retirement.
     */
    public function testOpenCircuitBreakerIsTransient(): void
    {
        $kind = $this->classifier->classify(
            new ProviderException('Service temporarily unavailable (circuit breaker is OPEN)', 'openai')
        );

        self::assertSame(FailureKind::Transient, $kind);
        self::assertFalse($kind->justifiesAutoDisable());
    }

    #[DataProvider('statusProvider')]
    public function testUpstreamStatusDecidesWhenTheMessageSaysNothing(int $status, FailureKind $expected): void
    {
        self::assertSame(
            $expected,
            $this->classifier->classify(new ProviderException('upstream rejected the call', 'openai', null, $status))
        );
    }

    /**
     * @return iterable<string, array{int, FailureKind}>
     */
    public static function statusProvider(): iterable
    {
        yield '400 bad request' => [400, FailureKind::UserError];
        yield '401 unauthorized' => [401, FailureKind::Credential];
        yield '402 payment required' => [402, FailureKind::Credential];
        yield '403 forbidden' => [403, FailureKind::Credential];
        yield '404 not found' => [404, FailureKind::Permanent];
        yield '410 gone' => [410, FailureKind::Permanent];
        yield '429 rate limited' => [429, FailureKind::Transient];
        yield '500 server error' => [500, FailureKind::Transient];
        yield '503 unavailable' => [503, FailureKind::Transient];
    }

    #[DataProvider('messageProvider')]
    public function testProviderWordingIsRecognised(string $message, FailureKind $expected): void
    {
        self::assertSame(
            $expected,
            $this->classifier->classify(new ProviderException($message, 'openai'))
        );
    }

    /**
     * @return iterable<string, array{string, FailureKind}>
     */
    public static function messageProvider(): iterable
    {
        yield 'openai model_not_found' => ['The model `gpt-4-vision-preview` does not exist or you do not have access to it', FailureKind::Permanent];
        yield 'groq decommissioned' => ['The model `llama-3.3-70b-versatile` has been decommissioned', FailureKind::Permanent];
        yield 'anthropic not_found_error' => ['not_found_error: model: claude-3-opus', FailureKind::Permanent];
        yield 'deprecated wording' => ['This model is deprecated and will be removed', FailureKind::Permanent];
        yield 'deprecated parameter is not a retired model' => ['The `functions` parameter is deprecated. Use `tools` instead.', FailureKind::Transient];
        yield 'invalid_request_error alone is not a user error' => ['invalid_request_error', FailureKind::Transient];
        yield 'invalid key' => ['Incorrect API key provided', FailureKind::Credential];
        yield 'exhausted quota' => ['You exceeded your current quota, please check your plan and billing details', FailureKind::Credential];
        yield 'overloaded' => ['Overloaded, please retry', FailureKind::Transient];
        yield 'timeout' => ['Request timed out after 60s', FailureKind::Transient];
        yield 'context length' => ['This model maximum context length is 8192 tokens', FailureKind::UserError];
        yield 'safety ratings' => ['The response was blocked by the safety ratings filter', FailureKind::UserError];
        yield 'unrelated safety outage stays transient' => ['Could not reach the safety endpoint', FailureKind::Transient];
    }

    /**
     * OpenAI wraps almost every 4xx as type=invalid_request_error, including
     * a bad key. That string must not hide a 401, or a credential outage is
     * filed as a user error and never pages anyone.
     */
    public function testUnauthorizedStatusWinsOverGenericInvalidRequestType(): void
    {
        $kind = $this->classifier->classify(
            new ProviderException('invalid_request_error', 'openai', null, 401)
        );

        self::assertSame(FailureKind::Credential, $kind);
    }

    /**
     * An unrecognised failure must land somewhere harmless. Transient is the
     * only default that cannot retire a model on its own.
     */
    public function testUnknownFailureDefaultsToTransient(): void
    {
        self::assertSame(
            FailureKind::Transient,
            $this->classifier->classify(new \RuntimeException('something went sideways'))
        );
    }
}
