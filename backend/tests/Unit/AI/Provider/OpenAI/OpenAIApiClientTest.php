<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider\OpenAI;

use App\AI\Credential\ProviderKeyStore;
use App\AI\Provider\OpenAI\OpenAIApiClient;
use App\AI\Provider\OpenAI\OpenAICurlTransport;
use OpenAI\Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenAIApiClientTest extends TestCase
{
    use OpenAIApiDouble;

    public function testTheExplicitKeyWinsAndTheKeyStoreAnswersOtherwise(): void
    {
        $store = $this->createStub(ProviderKeyStore::class);
        $store->method('getKey')->willReturnMap([['openai', 'stored-key']]);

        self::assertSame('explicit-key', (new OpenAIApiClient(new MockHttpClient(), new OpenAICurlTransport(new \Symfony\Component\HttpClient\MockHttpClient()), 'explicit-key', $store))->key());
        self::assertSame('stored-key', (new OpenAIApiClient(new MockHttpClient(), new OpenAICurlTransport(new \Symfony\Component\HttpClient\MockHttpClient()), '', $store))->key());
        self::assertNull((new OpenAIApiClient(new MockHttpClient(), new OpenAICurlTransport(new \Symfony\Component\HttpClient\MockHttpClient())))->sdk());
    }

    public function testTheSdkClientIsBuiltOncePerKeyAndAgainWhenTheKeyChanges(): void
    {
        $store = $this->createStub(ProviderKeyStore::class);
        $store->method('getKey')->willReturnOnConsecutiveCalls('key-a', 'key-a', 'key-b');
        $built = [];
        $factory = static function (string $key) use (&$built): Client {
            $built[] = $key;

            return \OpenAI::client($key);
        };
        $api = new OpenAIApiClient(new MockHttpClient(), new OpenAICurlTransport(new \Symfony\Component\HttpClient\MockHttpClient()), null, $store, $factory);

        $first = $api->sdk();

        self::assertSame($first, $api->sdk());
        self::assertNotSame($first, $api->sdk());
        self::assertSame(['key-a', 'key-b'], $built);
    }

    public function testAJsonPostCarriesTheBearerKeyTheBodyAndItsOptions(): void
    {
        $api = $this->api(httpResponses: [new MockResponse('ok')]);

        $api->postJson('https://api.openai.com/v1/audio/speech', ['input' => 'Hallo'], self::KEY, 120, ['Content-Type' => 'application/json'], ['buffer' => false])->getContent();

        $request = $this->http->requests()[0];
        self::assertSame(['POST', 'https://api.openai.com/v1/audio/speech', ['input' => 'Hallo'], 120.0], [$request['method'], $request['url'], $request['body'], $request['timeout']]);
        self::assertContains('Authorization: Bearer <secret>', $request['headers']);
    }

    public function testTheGptImageRequestGoesThroughTheCurlTransport(): void
    {
        $api = $this->api(curlAnswers: [['{"data":[]}', 200, '']]);

        self::assertSame(['{"data":[]}', 200, ''], $api->curlPostJson('https://api.openai.com/v1/images/generations', self::KEY, '{"n":1}', 120));
        self::assertSame([['url' => 'https://api.openai.com/v1/images/generations', 'body' => ['n' => 1], 'timeout' => 120]], $this->curlRequests);
    }
}
