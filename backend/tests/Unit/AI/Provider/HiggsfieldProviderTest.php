<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider;

use App\AI\Exception\ProviderCancelledException;
use App\AI\Exception\ProviderException;
use App\AI\Provider\HiggsfieldProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Unit tests for {@see HiggsfieldProvider}.
 *
 * All HTTP traffic is mocked. The poll interval is set to 0 so the submit ->
 * poll -> parse loop runs without real sleeps.
 */
class HiggsfieldProviderTest extends TestCase
{
    /**
     * @param array<int, array{status: int, data: array<string, mixed>}>              $responses
     *                                                                                           Ordered HTTP responses (submit first, then each poll)
     * @param list<array{method: string, url: string, options: array<string, mixed>}> $captured
     *                                                                                           Populated with each outgoing request for assertions
     */
    private function makeProvider(
        array $responses,
        array &$captured = [],
        string $platformKey = 'plat-key',
        string $platformSecret = 'plat-secret',
    ): HiggsfieldProvider {
        $mockResponses = array_map(function (array $r): ResponseInterface {
            $response = $this->createMock(ResponseInterface::class);
            $response->method('getStatusCode')->willReturn($r['status']);
            $response->method('toArray')->willReturn($r['data']);

            return $response;
        }, $responses);

        $index = 0;
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturnCallback(
            function (string $method, string $url, array $options = []) use (&$index, $mockResponses, &$captured): ResponseInterface {
                $captured[] = ['method' => $method, 'url' => $url, 'options' => $options];
                $response = $mockResponses[$index] ?? end($mockResponses);
                ++$index;

                return $response;
            }
        );

        return new HiggsfieldProvider(
            $httpClient,
            new NullLogger(),
            $platformKey,
            $platformSecret,
            0, // no sleep between polls
        );
    }

    public function testGenerateImageSubmitsThenPollsAndReturnsUrl(): void
    {
        $captured = [];
        $provider = $this->makeProvider([
            ['status' => 202, 'data' => [
                'status' => 'queued',
                'request_id' => 'req-1',
                'status_url' => 'https://platform.higgsfield.ai/requests/req-1/status',
            ]],
            ['status' => 200, 'data' => [
                'status' => 'completed',
                'request_id' => 'req-1',
                'images' => [['url' => 'https://img.example/out.png', 'revised_prompt' => 'a cat, enhanced']],
            ]],
        ], $captured);

        $result = $provider->generateImage('a cat', ['model' => 'higgsfield-ai/soul/standard']);

        self::assertCount(1, $result);
        self::assertSame('https://img.example/out.png', $result[0]['url']);
        self::assertArrayHasKey('b64_json', $result[0]);
        self::assertSame('a cat, enhanced', $result[0]['revised_prompt']);

        // First request must POST to the model endpoint; second must GET the status URL.
        self::assertSame('POST', $captured[0]['method']);
        self::assertSame('https://platform.higgsfield.ai/higgsfield-ai/soul/standard', $captured[0]['url']);
        self::assertSame('GET', $captured[1]['method']);
        self::assertSame('https://platform.higgsfield.ai/requests/req-1/status', $captured[1]['url']);
    }

    public function testUsesInlineCredentialsForAuthHeader(): void
    {
        $captured = [];
        $provider = $this->makeProvider([
            ['status' => 202, 'data' => ['status' => 'queued', 'request_id' => 'r', 'status_url' => 'https://platform.higgsfield.ai/requests/r/status']],
            ['status' => 200, 'data' => ['status' => 'completed', 'images' => [['url' => 'https://img/x.png']]]],
        ], $captured);

        $provider->generateImage('hi', [
            'model' => 'higgsfield-ai/soul/standard',
            'credentials' => ['api_key' => 'user-key', 'api_secret' => 'user-secret', 'source' => 'user'],
        ]);

        self::assertSame('Key user-key:user-secret', $captured[0]['options']['headers']['Authorization']);
    }

    public function testFallsBackToPlatformCredentialsWhenNoneSupplied(): void
    {
        $captured = [];
        $provider = $this->makeProvider([
            ['status' => 202, 'data' => ['status' => 'queued', 'request_id' => 'r', 'status_url' => 'https://platform.higgsfield.ai/requests/r/status']],
            ['status' => 200, 'data' => ['status' => 'completed', 'images' => [['url' => 'https://img/x.png']]]],
        ], $captured);

        $provider->generateImage('hi', ['model' => 'higgsfield-ai/soul/standard']);

        self::assertSame('Key plat-key:plat-secret', $captured[0]['options']['headers']['Authorization']);
    }

    public function testThrowsWhenNoCredentialsAvailable(): void
    {
        $captured = [];
        $provider = $this->makeProvider([], $captured, '', '');

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/API key not configured/i');

        $provider->generateImage('hi', ['model' => 'higgsfield-ai/soul/standard']);
    }

    public function testGenerateVideoReturnsVideoUrlWithMetadata(): void
    {
        $captured = [];
        $provider = $this->makeProvider([
            ['status' => 202, 'data' => ['status' => 'queued', 'request_id' => 'v1', 'status_url' => 'https://platform.higgsfield.ai/requests/v1/status']],
            ['status' => 200, 'data' => ['status' => 'in_progress', 'request_id' => 'v1']],
            ['status' => 200, 'data' => ['status' => 'completed', 'request_id' => 'v1', 'video' => ['url' => 'https://vid.example/out.mp4']]],
        ], $captured);

        $result = $provider->generateVideo('a dog runs', [
            'model' => 'higgsfield-ai/dop/standard',
            'image_url' => 'https://img.example/dog.jpg',
            'duration' => 5,
            'resolution' => '1080p',
        ]);

        self::assertCount(1, $result);
        self::assertSame('https://vid.example/out.mp4', $result[0]['url']);
        self::assertSame(5, $result[0]['duration']);
        self::assertSame('1080p', $result[0]['resolution']);

        // image_url must be forwarded in the submit body.
        self::assertSame('https://img.example/dog.jpg', $captured[0]['options']['json']['image_url']);
    }

    public function testNormalizesUnsupportedDurationToSupportedValueForDop(): void
    {
        $captured = [];
        $provider = $this->makeProvider([
            ['status' => 202, 'data' => ['status' => 'queued', 'request_id' => 'v', 'status_url' => 'https://platform.higgsfield.ai/requests/v/status']],
            ['status' => 200, 'data' => ['status' => 'completed', 'request_id' => 'v', 'video' => ['url' => 'https://vid/x.mp4']]],
        ], $captured);

        // 8s is the generic media-handler default but Higgsfield DoP only renders
        // 5s clips — the provider must snap it instead of sending an invalid value.
        $provider->generateVideo('x', [
            'model' => 'higgsfield-ai/dop/standard',
            'image_url' => 'https://img/x.jpg',
            'duration' => 8,
        ]);

        self::assertSame(5, $captured[0]['options']['json']['duration']);
    }

    public function testCancelDuringPollThrowsCancelledAndCallsCancelUrl(): void
    {
        $captured = [];
        $provider = $this->makeProvider([
            ['status' => 202, 'data' => [
                'status' => 'queued',
                'request_id' => 'v',
                'status_url' => 'https://platform.higgsfield.ai/requests/v/status',
                'cancel_url' => 'https://platform.higgsfield.ai/requests/v/cancel',
            ]],
        ], $captured);

        $threw = false;
        try {
            $provider->generateVideo('x', [
                'model' => 'higgsfield-ai/dop/standard',
                'image_url' => 'https://img/x.jpg',
                'cancel_check' => static fn (): bool => true,
            ]);
        } catch (ProviderCancelledException) {
            $threw = true;
        }

        self::assertTrue($threw, 'expected a ProviderCancelledException');
        // submit POST, then a cancel POST to the provider's cancel_url.
        self::assertSame('POST', $captured[0]['method']);
        self::assertSame('POST', $captured[1]['method']);
        self::assertSame('https://platform.higgsfield.ai/requests/v/cancel', $captured[1]['url']);
    }

    public function testFailedStatusThrows(): void
    {
        $provider = $this->makeProvider([
            ['status' => 202, 'data' => ['status' => 'queued', 'request_id' => 'r', 'status_url' => 'https://platform.higgsfield.ai/requests/r/status']],
            ['status' => 200, 'data' => ['status' => 'failed', 'request_id' => 'r', 'error' => 'model exploded']],
        ]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/model exploded/');

        $provider->generateImage('hi', ['model' => 'higgsfield-ai/soul/standard']);
    }

    public function testNsfwStatusThrowsContentBlocked(): void
    {
        $provider = $this->makeProvider([
            ['status' => 202, 'data' => ['status' => 'queued', 'request_id' => 'r', 'status_url' => 'https://platform.higgsfield.ai/requests/r/status']],
            ['status' => 200, 'data' => ['status' => 'nsfw', 'request_id' => 'r']],
        ]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/blocked/i');

        $provider->generateImage('hi', ['model' => 'higgsfield-ai/soul/standard']);
    }

    public function testSubmitAuthErrorThrows(): void
    {
        $provider = $this->makeProvider([
            ['status' => 401, 'data' => ['error' => 'bad key']],
        ]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/authentication error/i');

        $provider->generateImage('hi', ['model' => 'higgsfield-ai/soul/standard']);
    }

    public function testMissingImagesInCompletedResponseThrows(): void
    {
        $provider = $this->makeProvider([
            ['status' => 202, 'data' => ['status' => 'queued', 'request_id' => 'r', 'status_url' => 'https://platform.higgsfield.ai/requests/r/status']],
            ['status' => 200, 'data' => ['status' => 'completed', 'request_id' => 'r']],
        ]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/no images/i');

        $provider->generateImage('hi', ['model' => 'higgsfield-ai/soul/standard']);
    }

    public function testMetadataAndCapabilities(): void
    {
        $provider = $this->makeProvider([]);

        self::assertSame('higgsfield', $provider->getName());
        self::assertSame('Higgsfield', $provider->getDisplayName());
        self::assertContains('image_generation', $provider->getCapabilities());
        self::assertContains('video_generation', $provider->getCapabilities());
        self::assertTrue($provider->isAvailable());
    }

    public function testIsUnavailableWithoutPlatformCredentials(): void
    {
        $captured = [];
        $provider = $this->makeProvider([], $captured, '', '');
        self::assertFalse($provider->isAvailable());
    }
}
