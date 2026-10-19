<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\FreeApi;

use App\Repository\ConfigRepository;
use App\Service\Feature\FeatureFlagEnv;
use App\Service\FreeApi\FreeApiClient;
use App\Service\FreeApi\FreeApiConfig;
use App\Service\FreeApi\FreeApiException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FreeApiClientTest extends TestCase
{
    public function testIssueKeyPostsTheContractBodyWithoutRedirects(): void
    {
        $seen = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('{"key":"sk-free-1","key_id":1}', ['http_code' => 201]);
        });

        $answer = $this->client($http)->issueKey(['install_id' => 'abc', 'protocol' => 1]);

        $this->assertSame(['key' => 'sk-free-1', 'key_id' => 1], $answer);
        $this->assertSame('POST', $seen['method']);
        $this->assertSame('https://web.synaplan.com/v1/free/keys', $seen['url']);
        $this->assertSame(0, $seen['options']['max_redirects']);
        $this->assertSame('{"install_id":"abc","protocol":1}', $seen['options']['body']);
        $this->assertContains('X-Synaplan-Protocol: 1', $seen['options']['headers']);
        $this->assertNotContains('Authorization', array_map(static fn (string $h): string => strstr($h, ':', true) ?: $h, $seen['options']['headers']));
    }

    public function testStatusAndRevokeSendTheKeyAsBearer(): void
    {
        $calls = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $calls[] = [$method, $url, $options['headers']];

            return new MockResponse('GET' === $method ? '{"service":"ok"}' : '{"revoked":true}');
        });
        $client = $this->client($http);

        $this->assertSame(['service' => 'ok'], $client->status('sk-free-1'));
        $client->revokeKey('sk-free-1');

        $this->assertSame('https://web.synaplan.com/v1/free/status', $calls[0][1]);
        $this->assertSame(['DELETE', 'https://web.synaplan.com/v1/free/keys/self'], [$calls[1][0], $calls[1][1]]);
        $this->assertContains('Authorization: Bearer sk-free-1', $calls[1][2]);
    }

    public function testARefusalCarriesTheServerCodeAndRetryAfter(): void
    {
        $http = new MockHttpClient(new MockResponse(
            '{"error":{"message":"The Synaplan Demo is fully used for today.","type":"rate_limit_error","param":null,"code":"freeapi_full_today"}}',
            ['http_code' => 429, 'response_headers' => ['Retry-After' => '3600']],
        ));

        try {
            $this->client($http)->status('sk-free-1');
            $this->fail('expected a refusal');
        } catch (FreeApiException $e) {
            $this->assertSame('freeapi_full_today', $e->errorCode);
            $this->assertSame(429, $e->httpStatus);
            $this->assertSame(3600, $e->retryAfterSeconds);
            $this->assertSame('The Synaplan Demo is fully used for today.', $e->getMessage());
        }
    }

    public function testAForeignErrorCodeIsNeverPassedOn(): void
    {
        $http = new MockHttpClient([
            new MockResponse('{"error":{"code":"invalid_api_key","message":"nope"}}', ['http_code' => 401]),
            new MockResponse('<html>bad gateway</html>', ['http_code' => 502]),
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'https://evil.example/']]),
        ]);
        $client = $this->client($http);

        foreach ([FreeApiException::INVALID_KEY, FreeApiException::INVALID_RESPONSE, FreeApiException::INVALID_RESPONSE] as $expected) {
            try {
                $client->status('sk-free-1');
                $this->fail('expected a refusal');
            } catch (FreeApiException $e) {
                $this->assertSame($expected, $e->errorCode);
            }
        }
    }

    public function testATransportFailureIsUnreachable(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => throw new TransportException('connection refused'));

        try {
            $this->client($http)->status('sk-free-1');
            $this->fail('expected a refusal');
        } catch (FreeApiException $e) {
            $this->assertSame(FreeApiException::UNREACHABLE, $e->errorCode);
            $this->assertInstanceOf(TransportException::class, $e->getPrevious());
        }
    }

    public function testAnOversizedAnswerIsCutOff(): void
    {
        $http = new MockHttpClient(new MockResponse(str_repeat('a', 70_000)));

        try {
            $this->client($http)->status('sk-free-1');
            $this->fail('expected a refusal');
        } catch (FreeApiException $e) {
            $this->assertSame(FreeApiException::INVALID_RESPONSE, $e->errorCode);
        }
    }

    public function testNoServerMeansNoRequest(): void
    {
        $http = new MockHttpClient(function (): MockResponse {
            $this->fail('no request may leave without a configured server');
        });

        try {
            $this->client($http, '')->status('sk-free-1');
            $this->fail('expected a refusal');
        } catch (FreeApiException $e) {
            $this->assertSame(FreeApiException::NOT_CONFIGURED, $e->errorCode);
        }
        $this->assertSame(0, $http->getRequestsCount());
    }

    private function client(MockHttpClient $http, string $baseUrl = 'https://web.synaplan.com'): FreeApiClient
    {
        $config = new FreeApiConfig($this->createStub(ConfigRepository::class), new FeatureFlagEnv([]), $baseUrl, 'prod', false, '4.9.0', 'selfhost');

        return new FreeApiClient($http, $config);
    }
}
