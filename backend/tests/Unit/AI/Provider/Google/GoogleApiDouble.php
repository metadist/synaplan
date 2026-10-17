<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider\Google;

use App\AI\Provider\Google\GoogleApiClient;
use App\Tests\Characterization\Support\ProviderHttpRecorder;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A GoogleApiClient on a recording HTTP double for the capability tests.
 */
trait GoogleApiDouble
{
    private const KEY = 'test-google-key';
    private const VERTEX_TOKEN = 'test-vertex-token';

    private ProviderHttpRecorder $recorder;

    /**
     * @param list<MockResponse> $responses
     */
    private function api(array $responses, ?string $projectId = null, ?string $vertexAccessToken = null): GoogleApiClient
    {
        $this->recorder = new ProviderHttpRecorder($responses, [self::KEY, self::VERTEX_TOKEN]);

        return new GoogleApiClient($this->recorder->client(), new NullLogger(), self::KEY, null, $projectId, 'us-central1', $vertexAccessToken);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data, int $status = 200): MockResponse
    {
        return new MockResponse((string) json_encode($data), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requests(): array
    {
        return $this->recorder->requests();
    }
}
