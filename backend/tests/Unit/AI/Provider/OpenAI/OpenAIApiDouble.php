<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider\OpenAI;

use App\AI\Provider\OpenAI\OpenAIApiClient;
use App\AI\Provider\OpenAI\OpenAICurlTransport;
use App\Tests\Characterization\Support\OpenAISdkRecorder;
use App\Tests\Characterization\Support\ProviderHttpRecorder;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * An OpenAIApiClient on recording doubles of its three transports for the
 * capability tests: the SDK, Symfony's client and the cURL call.
 */
trait OpenAIApiDouble
{
    private const KEY = 'test-openai-key';

    private OpenAISdkRecorder $sdk;
    private ProviderHttpRecorder $http;

    /** @var list<array{url: string, body: mixed, timeout: int}> */
    private array $curlRequests = [];

    /**
     * @param list<Response>                                  $sdkResponses
     * @param list<MockResponse>                              $httpResponses
     * @param list<array{0: string|false, 1: int, 2: string}> $curlAnswers
     */
    private function api(array $sdkResponses = [], array $httpResponses = [], array $curlAnswers = [], ?string $key = self::KEY): OpenAIApiClient
    {
        $this->sdk = new OpenAISdkRecorder($sdkResponses, [self::KEY]);
        $this->http = new ProviderHttpRecorder($httpResponses, [self::KEY]);
        $curl = $this->createStub(OpenAICurlTransport::class);
        $curl->method('postJson')->willReturnCallback(function (string $url, ?string $key, string|false $body, int $timeout) use (&$curlAnswers): array {
            $this->curlRequests[] = ['url' => $url, 'body' => json_decode((string) $body, true), 'timeout' => $timeout];

            return array_shift($curlAnswers) ?? ['', 0, 'unexpected cURL request'];
        });

        return new OpenAIApiClient($this->http->client(), $curl, $key, null, $this->sdk->factory());
    }

    /**
     * @return list<string> method and path of every SDK request
     */
    private function sdkCalls(): array
    {
        return array_map(static fn (array $request): string => $request['method'].' '.substr($request['url'], \strlen('https://api.openai.com')), $this->sdk->requests());
    }
}
