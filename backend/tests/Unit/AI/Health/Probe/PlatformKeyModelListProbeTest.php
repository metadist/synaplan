<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Health\Probe;

use App\AI\Catalog\ProviderCatalog;
use App\AI\Credential\ProviderKeyCatalog;
use App\AI\Credential\ProviderKeyStore;
use App\AI\Health\FailureKind;
use App\AI\Health\Probe\PlatformKeyModelListProbe;
use App\AI\Health\Probe\ProbeResult;
use App\AI\Service\ProviderModelInventoryInterface;
use App\Repository\ConfigRepository;
use App\Service\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HuggingFace's whoami-v2 is not a model list but still a credential check.
 * Media/speech providers stay out — they have no cheap authenticated probe.
 */
final class PlatformKeyModelListProbeTest extends TestCase
{
    public function testSupportsListingProvidersAndHuggingFaceCredentialCheck(): void
    {
        $probe = new PlatformKeyModelListProbe(
            $this->createStub(HttpClientInterface::class),
            $this->createStub(ProviderKeyStore::class),
            $this->createStub(ProviderModelInventoryInterface::class),
            new NullLogger(),
            new ProviderKeyCatalog(ProviderCatalog::builtIn()),
        );

        self::assertTrue($probe->supports('groq'));
        self::assertTrue($probe->supports('huggingface'), 'whoami-v2 still tells a bad key from an outage');
        self::assertFalse($probe->supports('thehive'));
        self::assertFalse($probe->supports('higgsfield'));
        self::assertFalse($probe->supports('elevenlabs'));
    }

    public function testAnthropicPaginationFollowsHasMoreAndAfterId(): void
    {
        $requested = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = $url;
            if (!str_contains($url, 'after_id=')) {
                return new MockResponse(json_encode([
                    'data' => [['id' => 'claude-a']],
                    'has_more' => true,
                    'last_id' => 'claude-a',
                ], \JSON_THROW_ON_ERROR));
            }

            return new MockResponse(json_encode([
                'data' => [['id' => 'claude-b']],
                'has_more' => false,
                'last_id' => 'claude-b',
            ], \JSON_THROW_ON_ERROR));
        });

        $keyStore = new ProviderKeyStore(
            $this->createStub(ConfigRepository::class),
            new EncryptionService('unit-test-secret', new NullLogger()),
            new NullLogger(),
            new ProviderKeyCatalog(ProviderCatalog::builtIn()),
            ['anthropic' => 'unit-test-provider-key-0123456789'],
        );

        $result = (new PlatformKeyModelListProbe(
            $client,
            $keyStore,
            $this->createStub(ProviderModelInventoryInterface::class),
            new NullLogger(),
            new ProviderKeyCatalog(ProviderCatalog::builtIn()),
        ))->probe('anthropic');

        self::assertTrue($result->isOk());
        self::assertContains('claude-a', $result->modelIds);
        self::assertContains('claude-b', $result->modelIds);
        self::assertCount(2, $requested);
        self::assertStringContainsString('after_id=claude-a', $requested[1]);
    }

    public function testATransientTransportFailureIsRetriedOnceAndThenSucceeds(): void
    {
        $attempts = 0;
        $client = new MockHttpClient(function () use (&$attempts): MockResponse {
            ++$attempts;
            if (1 === $attempts) {
                throw new \RuntimeException('Failed to connect to api.anthropic.com port 443 after 4001 ms');
            }

            return new MockResponse(json_encode([
                'data' => [['id' => 'claude-a']],
                'has_more' => false,
            ], \JSON_THROW_ON_ERROR));
        });

        $result = $this->probe($client);

        self::assertTrue($result->isOk());
        self::assertContains('claude-a', $result->modelIds);
        self::assertSame(2, $attempts);
    }

    public function testAPersistentTransportFailureStaysTransientAfterOneRetry(): void
    {
        $attempts = 0;
        $client = new MockHttpClient(function () use (&$attempts): MockResponse {
            ++$attempts;

            throw new \RuntimeException('Failed to connect to api.anthropic.com port 443 after 4001 ms');
        });

        $result = $this->probe($client);

        self::assertTrue($result->isFailed());
        self::assertSame(FailureKind::Transient, $result->kind);
        self::assertStringContainsString('Could not reach the provider API', $result->message);
        self::assertSame(2, $attempts);
    }

    public function testAnUpstreamServerErrorIsRetriedOnce(): void
    {
        $attempts = 0;
        $client = new MockHttpClient(function () use (&$attempts): MockResponse {
            ++$attempts;
            if (1 === $attempts) {
                return new MockResponse('{}', ['http_code' => 503]);
            }

            return new MockResponse(json_encode([
                'data' => [['id' => 'claude-a']],
                'has_more' => false,
            ], \JSON_THROW_ON_ERROR));
        });

        $result = $this->probe($client);

        self::assertTrue($result->isOk());
        self::assertContains('claude-a', $result->modelIds);
        self::assertSame(2, $attempts);
    }

    public function testARejectedKeyIsNotRetried(): void
    {
        $attempts = 0;
        $client = new MockHttpClient(function () use (&$attempts): MockResponse {
            ++$attempts;

            return new MockResponse('{}', ['http_code' => 401]);
        });

        $result = $this->probe($client);

        self::assertTrue($result->isFailed());
        self::assertSame(FailureKind::Credential, $result->kind);
        self::assertSame(1, $attempts);
    }

    private function probe(HttpClientInterface $client): ProbeResult
    {
        $keyStore = new ProviderKeyStore(
            $this->createStub(ConfigRepository::class),
            new EncryptionService('unit-test-secret', new NullLogger()),
            new NullLogger(),
            new ProviderKeyCatalog(ProviderCatalog::builtIn()),
            ['anthropic' => 'unit-test-provider-key-0123456789'],
        );

        return (new PlatformKeyModelListProbe(
            $client,
            $keyStore,
            $this->createStub(ProviderModelInventoryInterface::class),
            new NullLogger(),
            new ProviderKeyCatalog(ProviderCatalog::builtIn()),
        ))->probe('anthropic');
    }
}
