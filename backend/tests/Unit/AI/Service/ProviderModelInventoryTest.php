<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Service;

use App\AI\Credential\ProviderKeyStore;
use App\AI\Service\ModelProbeResult;
use App\AI\Service\ProviderModelInventory;
use App\AI\Service\ProviderModelListing;
use App\Repository\ConfigRepository;
use App\Service\EncryptionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ProviderModelInventoryTest extends TestCase
{
    public function testParsesOpenAiCompatibleListing(): void
    {
        $listing = $this->inventory(new MockResponse('{"data":[{"id":"openai/gpt-oss-120b"},{"id":"Whisper-Large-V3"}]}'))
            ->fetch('groq');

        $this->assertTrue($listing->isConclusive());
        $this->assertTrue($listing->serves('openai/gpt-oss-120b'));
        $this->assertTrue($listing->serves('whisper-large-v3'), 'Model ids must match case-insensitively.');
        $this->assertFalse($listing->serves('llama-3.3-70b-versatile'));
    }

    public function testParsesGeminiListingAndStripsTheModelsPrefix(): void
    {
        $listing = $this->inventory(new MockResponse('{"models":[{"name":"models/gemini-2.5-flash"}]}'), 'google')
            ->fetch('google');

        $this->assertTrue($listing->isConclusive());
        $this->assertTrue($listing->serves('gemini-2.5-flash'));
    }

    /**
     * An empty or unrecognised payload must never become a conclusive empty
     * catalog — that would report every model of the provider as discontinued.
     */
    public function testEmptyPayloadIsInconclusiveRatherThanAnEmptyCatalog(): void
    {
        $listing = $this->inventory(new MockResponse('{"data":[]}'))->fetch('groq');

        $this->assertFalse($listing->isConclusive());
        $this->assertSame(ProviderModelListing::STATUS_UNREACHABLE, $listing->status);
    }

    public function testHttpErrorIsInconclusive(): void
    {
        $listing = $this->inventory(new MockResponse('{"error":"nope"}', ['http_code' => 500]))->fetch('groq');

        $this->assertFalse($listing->isConclusive());
        $this->assertSame(ProviderModelListing::STATUS_UNREACHABLE, $listing->status);
    }

    public function testTransportFailureIsInconclusive(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new \RuntimeException('DNS failure');
        });

        $listing = $this->inventoryWithClient($client)->fetch('groq');

        $this->assertFalse($listing->isConclusive());
        $this->assertSame(ProviderModelListing::STATUS_UNREACHABLE, $listing->status);
    }

    public function testProviderWithoutKeyIsReportedAsNotConfigured(): void
    {
        $listing = $this->inventory(new MockResponse('{"data":[{"id":"x"}]}'), 'google')->fetch('mistral');

        $this->assertSame(ProviderModelListing::STATUS_NOT_CONFIGURED, $listing->status);
        $this->assertFalse($listing->isConclusive());
    }

    /**
     * HuggingFace validates keys against `whoami-v2`, which lists no models, so
     * it can never contribute an availability verdict.
     */
    public function testHuggingFaceHasNoListingEndpoint(): void
    {
        $listing = $this->inventory(new MockResponse('{}'), 'huggingface')->fetch('huggingface');

        $this->assertSame(ProviderModelListing::STATUS_NO_LISTING_ENDPOINT, $listing->status);
    }

    public function testSelfHostedProviderHasNoListingEndpoint(): void
    {
        $listing = $this->inventory(new MockResponse('{}'))->fetch('ollama');

        $this->assertSame(ProviderModelListing::STATUS_NO_LISTING_ENDPOINT, $listing->status);
    }

    public function testProbeVerdicts(): void
    {
        $expected = [
            200 => ModelProbeResult::Alive,
            404 => ModelProbeResult::Gone,
            400 => ModelProbeResult::Gone,
            401 => ModelProbeResult::Inconclusive,
            429 => ModelProbeResult::Inconclusive,
            500 => ModelProbeResult::Inconclusive,
        ];

        foreach ($expected as $status => $verdict) {
            $actual = $this->inventory(new MockResponse('{}', ['http_code' => $status]))
                ->probe('groq', 'some-model');

            $this->assertSame($verdict, $actual, sprintf('HTTP %d must map to %s.', $status, $verdict->name));
        }
    }

    public function testProbeAddressesTheModelUnderTheListingUrl(): void
    {
        $requested = [];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = $url;

            return new MockResponse('{}', ['http_code' => 404]);
        });

        $this->inventoryWithClient($client)->probe('groq', 'meta-llama/llama-4-scout-17b-16e-instruct');

        $this->assertSame(
            ['https://api.groq.com/openai/v1/models/meta-llama/llama-4-scout-17b-16e-instruct'],
            $requested,
            'A slash inside a model id is part of the path and must survive unescaped.',
        );
    }

    private function inventory(MockResponse $response, string $provider = 'groq'): ProviderModelInventory
    {
        return $this->inventoryWithClient(new MockHttpClient($response), $provider);
    }

    private function inventoryWithClient(MockHttpClient $client, string $provider = 'groq'): ProviderModelInventory
    {
        $keyStore = new ProviderKeyStore(
            $this->createStub(ConfigRepository::class),
            new EncryptionService('unit-test-secret', new NullLogger()),
            new NullLogger(),
            [$provider => 'unit-test-provider-key-0123456789'],
        );

        return new ProviderModelInventory($client, $keyStore, new NullLogger());
    }
}
