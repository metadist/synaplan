<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider;

use App\AI\Provider\OpenAI\OpenAICurlTransport;
use App\AI\Provider\OpenAIProvider;
use App\Tests\Characterization\Support\OfflineHttpGuard;
use App\Tests\Characterization\Support\OpenAISdkRecorder;
use App\Tests\Characterization\Support\ProviderHttpRecorder;
use GuzzleHttp\Psr7\Response;
use OpenAI\Responses\Embeddings\CreateResponse as EmbeddingResponse;
use OpenAI\Responses\Models\RetrieveResponse as ModelResponse;
use OpenAI\Responses\Responses\CreateResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * OpenAIProvider sends through three transports: the OpenAI SDK, Symfony's
 * HTTP client and native cURL. With the SDK factory, the HTTP client and the
 * cURL transport injected as doubles, no request may leave the process; the
 * guard records any request that reaches PSR-18 discovery instead.
 */
final class OpenAIProviderOfflineTest extends TestCase
{
    private const KEY = 'test-openai-key';

    protected function setUp(): void
    {
        OfflineHttpGuard::install();
    }

    protected function tearDown(): void
    {
        OfflineHttpGuard::uninstall();
    }

    public function testTheGuardCatchesARequestThatBypassesTheInjectedClient(): void
    {
        try {
            \OpenAI::client(self::KEY)->models()->list();
            self::fail('The default SDK client must not reach the network.');
        } catch (\RuntimeException) {
        }

        self::assertSame(['GET https://api.openai.com/v1/models'], OfflineHttpGuard::attempts());
    }

    public function testEveryTransportGoesThroughItsDouble(): void
    {
        $sdk = new OpenAISdkRecorder([
            self::json(ModelResponse::fake(['id' => 'gpt-4o'])->toArray()),
            self::json(CreateResponse::fake()->toArray()),
            self::json(EmbeddingResponse::fake()->toArray()),
        ], [self::KEY]);
        $http = new ProviderHttpRecorder([new MockResponse('mp3-bytes', ['response_headers' => ['content-type' => 'audio/mpeg']])], [self::KEY]);
        $curlCalls = [];
        $curl = $this->createStub(OpenAICurlTransport::class);
        $curl->method('postJson')->willReturnCallback(static function (string $url, ?string $key, string|false $body, int $timeout) use (&$curlCalls): array {
            $curlCalls[] = [$url, $timeout];

            return [(string) json_encode(['data' => [['b64_json' => 'AAAA']]]), 200, ''];
        });
        $provider = new OpenAIProvider(new NullLogger(), $http->client(), self::KEY, sys_get_temp_dir(), sdkFactory: $sdk->factory(), curl: $curl);

        $provider->chat([['role' => 'user', 'content' => 'Hi']], ['model' => 'gpt-4o']);
        $provider->embed('Hello', ['model' => 'text-embedding-3-small']);
        $provider->generateImage('A red bicycle', ['model' => 'gpt-image-1']);
        $audio = implode('', iterator_to_array($provider->synthesizeStream('Hallo'), false));

        self::assertSame([], OfflineHttpGuard::attempts());
        self::assertSame(
            ['GET https://api.openai.com/v1/models/gpt-4o', 'POST https://api.openai.com/v1/responses', 'POST https://api.openai.com/v1/embeddings'],
            array_map(static fn (array $request): string => $request['method'].' '.$request['url'], $sdk->requests()),
        );
        self::assertSame([['https://api.openai.com/v1/images/generations', 120]], $curlCalls);
        self::assertSame(['https://api.openai.com/v1/audio/speech', 'mp3-bytes'], [$http->requests()[0]['url'], $audio]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }
}
