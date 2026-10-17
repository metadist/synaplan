<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider\Google;

use App\AI\Credential\ProviderKeyStore;
use App\AI\Exception\ProviderException;
use App\AI\Provider\Google\GoogleApiClient;
use App\Tests\Characterization\Support\ProviderHttpRecorder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GoogleApiClientTest extends TestCase
{
    public function testTheExplicitKeyWinsAndTheKeyStoreAnswersOtherwise(): void
    {
        $store = $this->createStub(ProviderKeyStore::class);
        $store->method('getKey')->willReturnMap([['google', 'stored-key']]);

        self::assertSame('explicit-key', $this->client(apiKey: 'explicit-key', keyStore: $store)->key());
        self::assertSame('stored-key', $this->client(apiKey: '', keyStore: $store)->key());

        $this->expectExceptionObject(ProviderException::missingApiKey('google', 'GOOGLE_GEMINI_API_KEY'));
        $this->client()->requireKey();
    }

    public function testVertexNeedsAProjectAndItsOwnToken(): void
    {
        self::assertFalse($this->client(projectId: 'p', vertexAccessToken: '')->usesVertex());
        self::assertFalse($this->client(projectId: '', vertexAccessToken: 't')->usesVertex());

        $vertex = $this->client(projectId: 'synaplan', region: 'europe-west4', vertexAccessToken: 't');
        self::assertTrue($vertex->usesVertex());
        self::assertSame('https://europe-west4-aiplatform.googleapis.com/v1/projects/synaplan/locations/europe-west4/publishers/google/models/imagen-4:predict', $vertex->vertexModelUrl('imagen-4', 'predict'));
    }

    public function testRequestsCarryTheKeyTheBodyAndTheTimeLimits(): void
    {
        $recorder = new ProviderHttpRecorder([new MockResponse('{}'), new MockResponse('{}'), new MockResponse('{}')], ['test-api-key', 'test-vertex-token']);
        $api = new GoogleApiClient($recorder->client(), new NullLogger(), 'test-api-key', null, 'synaplan', 'us-central1', 'test-vertex-token');

        $api->postJson($api->modelUrl('gemini-2.5-flash', 'generateContent'), ['a' => 1], 'test-api-key', 60, ['max_duration' => 90])->getStatusCode();
        $api->send('GET', $api->url('operations/op-1'), 'test-api-key', 30)->getStatusCode();
        $api->postVertexJson($api->vertexModelUrl('imagen-4', 'predict'), ['b' => 2], 120)->getStatusCode();

        $requests = $recorder->requests();
        self::assertSame(['POST', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent', ['a' => 1], 60.0, 90.0], [$requests[0]['method'], $requests[0]['url'], $requests[0]['body'], $requests[0]['timeout'], $requests[0]['max_duration']]);
        self::assertContains('x-goog-api-key: <secret>', $requests[0]['headers']);
        self::assertSame(['GET', 'https://generativelanguage.googleapis.com/v1beta/operations/op-1', 30.0], [$requests[1]['method'], $requests[1]['url'], $requests[1]['timeout']]);
        self::assertContains('Authorization: Bearer <secret>', $requests[2]['headers']);
        self::assertNotContains('x-goog-api-key: <secret>', $requests[2]['headers']);
    }

    public function testABlockedAnswerBecomesAProviderException(): void
    {
        $api = $this->client();
        $api->checkFinishReason(['candidates' => [['finishReason' => 'MAX_TOKENS']]]);

        try {
            $api->checkFinishReason(['candidates' => [['finishReason' => 'SAFETY', 'content' => ['parts' => [['text' => 'No.']]]]]]);
            self::fail('A SAFETY finish must be refused.');
        } catch (ProviderException $e) {
            self::assertSame(['block_reason' => 'SAFETY', 'text_response' => 'No.'], $e->getContext());
        }

        $this->expectExceptionMessage('Content blocked by google (PROHIBITED_CONTENT)');
        $api->checkFinishReason(['promptFeedback' => ['blockReason' => 'PROHIBITED_CONTENT']]);
    }

    private function client(?string $apiKey = null, ?ProviderKeyStore $keyStore = null, ?string $projectId = null, string $region = 'us-central1', ?string $vertexAccessToken = null): GoogleApiClient
    {
        return new GoogleApiClient(new MockHttpClient(), new NullLogger(), $apiKey, $keyStore, $projectId, $region, $vertexAccessToken);
    }
}
