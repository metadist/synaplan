<?php

declare(strict_types=1);

namespace App\Tests\AI\Credential;

use App\AI\Credential\ProviderKeyValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ProviderKeyValidatorTest extends TestCase
{
    private function makeValidator(MockHttpClient $client): ProviderKeyValidator
    {
        return new ProviderKeyValidator($client, new NullLogger());
    }

    public function testSuccessfulValidation(): void
    {
        $client = new MockHttpClient(new MockResponse('{"data":[]}', ['http_code' => 200]));

        $result = $this->makeValidator($client)->validate('groq', 'gsk_valid');

        self::assertTrue($result['ok']);
        self::assertSame(200, $result['status'] ?? null);
    }

    public function testRejectedKeyReportsProviderRejection(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 401]));

        $result = $this->makeValidator($client)->validate('openai', 'sk-invalid');

        self::assertFalse($result['ok']);
        self::assertSame('The provider rejected this API key.', $result['error'] ?? null);
    }

    public function testRateLimitedKeyIsAcceptedAsValid(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 429]));

        $result = $this->makeValidator($client)->validate('groq', 'gsk_rate_limited');

        self::assertTrue($result['ok'], 'a 429 means the key authenticated far enough to be rate-limited');
    }

    public function testKeyIsInterpolatedIntoAuthHeaderAndNeverIntoTheUrl(): void
    {
        $seenAuth = null;
        $seenUrl = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seenAuth, &$seenUrl): MockResponse {
            $seenUrl = $url;
            foreach ($options['headers'] as $header) {
                if (str_starts_with(strtolower($header), 'authorization:')) {
                    $seenAuth = $header;
                }
            }

            return new MockResponse('', ['http_code' => 200]);
        });

        $this->makeValidator($client)->validate('groq', 'gsk_secret123');

        self::assertNotNull($seenAuth, 'validation request must send an auth header');
        self::assertStringContainsString('gsk_secret123', $seenAuth);
        self::assertStringNotContainsString('gsk_secret123', (string) $seenUrl);
    }

    public function testEmptyKeyAndUnknownProviderFailFastWithoutHttp(): void
    {
        $client = new MockHttpClient(function (): MockResponse {
            self::fail('no HTTP request may be sent for invalid input');
        });
        $validator = $this->makeValidator($client);

        self::assertFalse($validator->validate('groq', '  ')['ok']);
        self::assertFalse($validator->validate('not-a-provider', 'some-key')['ok']);
    }

    /**
     * TheHive has no free authenticated endpoint: the key is accepted as-is,
     * but the result must say so — "valid" would be a lie.
     */
    public function testUntestableProviderIsAcceptedWithoutHttpAndFlaggedUntested(): void
    {
        $client = new MockHttpClient(function (): MockResponse {
            self::fail('no HTTP request may be sent for a provider without a validation endpoint');
        });

        $result = $this->makeValidator($client)->validate('thehive', 'hive-key');

        self::assertTrue($result['ok']);
        self::assertFalse($result['tested']);
    }

    public function testSecretProviderInterpolatesBothHalvesAndRefusesAHalfPair(): void
    {
        $seenAuth = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seenAuth): MockResponse {
            foreach ($options['headers'] as $header) {
                if (str_starts_with(strtolower($header), 'authorization:')) {
                    $seenAuth = $header;
                }
            }

            return new MockResponse('', ['http_code' => 200]);
        });
        $validator = $this->makeValidator($client);

        $result = $validator->validate('higgsfield', 'hf-key', 'hf-secret');
        self::assertTrue($result['ok']);
        self::assertTrue($result['tested']);
        self::assertSame('Authorization: Key hf-key:hf-secret', $seenAuth);

        $half = $validator->validate('higgsfield', 'hf-key');
        self::assertFalse($half['ok']);
        self::assertStringContainsString('secret', $half['error'] ?? '');
    }

    public function testTransportErrorIsReportedNotThrown(): void
    {
        $client = new MockHttpClient(function (): MockResponse {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('connection refused');
        });

        $result = $this->makeValidator($client)->validate('groq', 'gsk_key');

        self::assertFalse($result['ok']);
        self::assertStringContainsString('Could not reach the provider API', $result['error'] ?? '');
    }
}
