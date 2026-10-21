<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Readiness probe: Redis and a fixed body. No provider status, no process.
 */
class HealthReadyControllerTest extends WebTestCase
{
    private $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testReadyAnswersWithoutProviderOrWhisperStatus(): void
    {
        $this->client->request('GET', '/api/health/ready');
        $this->assertResponseIsSuccessful();

        $started = hrtime(true);
        $this->client->request('GET', '/api/health/ready');
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;

        $this->assertResponseIsSuccessful();
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertResponseHeaderSame('Content-Type', 'application/json');
        // A warmed request in this test kernel measured about 14 ms. The
        // production budget (5 ms) is make perf-runtime, in worker mode.
        // 25 ms still fails if the probe starts a program or loads providers.
        $this->assertLessThan(25, $elapsedMs);

        $body = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(['status', 'redis'], array_keys($body));
        $this->assertSame('ok', $body['status']);
        $this->assertTrue($body['redis']['available']);
        $this->assertTrue($body['redis']['skipped'] ?? false);
        $this->assertArrayNotHasKey('providers', $body);
        $this->assertArrayNotHasKey('whisper', $body);
        $this->assertArrayNotHasKey('timestamp', $body);
    }

    public function testReadyDoesNotRequireAuth(): void
    {
        $this->client->request('GET', '/api/health/ready');

        $this->assertResponseIsSuccessful();
    }

    public function testReadyOnlyAcceptsGet(): void
    {
        $this->client->request('POST', '/api/health/ready');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }
}
