<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use App\AI\Provider\PiperProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PiperProviderStatusTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir().'/syn_piper_status_'.uniqid();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->baseDir);
    }

    public function testHealthAndVoiceProbesAreTimeBounded(): void
    {
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[$url] = $options;

            return new MockResponse(str_ends_with($url, '/health') ? '{"status":"ok"}' : '[]');
        });
        $provider = new PiperProvider(
            $client,
            'http://tts:10200',
            new NullLogger(),
            new Filesystem(),
            $this->baseDir.'/temp',
            $this->baseDir.'/uploads',
        );

        self::assertTrue($provider->getStatus()['healthy']);
        self::assertSame([], $provider->getVoices());

        foreach (['http://tts:10200/health', 'http://tts:10200/api/voices'] as $url) {
            self::assertSame(2.0, $requests[$url]['timeout'] ?? null, $url);
            self::assertSame(2.0, $requests[$url]['max_duration'] ?? null, $url);
        }
    }
}
