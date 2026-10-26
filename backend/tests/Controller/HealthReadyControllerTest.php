<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Readiness probe: Redis and a fixed body. No provider status, no process.
 */
final class HealthReadyControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testReadyAnswersWithoutProviderOrWhisperStatus(): void
    {
        $this->client->request('GET', '/api/health/ready');

        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertResponseHeaderSame('Content-Type', 'application/json');

        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame(['status', 'redis'], array_keys($body));
        $this->assertSame('ok', $body['status']);
        $this->assertTrue($body['redis']['available']);
        $this->assertTrue($body['redis']['skipped'] ?? false);
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
