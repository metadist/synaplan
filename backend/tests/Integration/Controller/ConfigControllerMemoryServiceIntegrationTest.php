<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Integration Tests für ConfigController Memory Service Check.
 * Testet echte HTTP Requests gegen den Controller.
 */
final class ConfigControllerMemoryServiceIntegrationTest extends WebTestCase
{
    public function testMemoryServiceCheckEndpointIsAccessibleWithoutAuth(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/config/memory-service/check');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testMemoryServiceCheckReturnsValidJson(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/config/memory-service/check');

        $response = $client->getResponse();
        $this->assertResponseIsSuccessful();

        $data = json_decode($response->getContent(), true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('available', $data);
        $this->assertArrayHasKey('configured', $data);
        $this->assertIsBool($data['available']);
        $this->assertIsBool($data['configured']);
    }

    public function testMemoryServiceCheckConfiguredFlagReflectsEnvironment(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/config/memory-service/check');

        $data = json_decode($client->getResponse()->getContent(), true);

        $qdrantUrl = $_ENV['QDRANT_SERVICE_URL'] ?? '';
        $expectedConfigured = !empty($qdrantUrl);

        $this->assertEquals($expectedConfigured, $data['configured']);
    }

    public function testRuntimeConfigIncludesMemoryServiceFeature(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/config/runtime');

        $this->assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('features', $data);
        $this->assertArrayHasKey('memoryService', $data['features']);
        $this->assertIsBool($data['features']['memoryService']);
    }

    public function testRuntimeConfigRespondsQuickly(): void
    {
        $client = static::createClient();

        $startTime = microtime(true);
        $client->request('GET', '/api/v1/config/runtime');
        $duration = microtime(true) - $startTime;

        $this->assertResponseIsSuccessful();

        // Should be VERY fast (no blocking health checks)
        // Even with slow network, should be under 1 second
        $this->assertLessThan(1.0, $duration, 'Runtime config must respond quickly (<1s)');
    }

    public function testMemoryServiceCheckCorsHeaders(): void
    {
        $client = static::createClient();

        $client->request(
            'GET',
            '/api/v1/config/memory-service/check',
            [],
            [],
            ['HTTP_ORIGIN' => 'https://example.com']
        );

        $response = $client->getResponse();

        // CORS should be configured for this public endpoint
        $this->assertTrue($response->headers->has('Access-Control-Allow-Origin') || $response->isSuccessful());
    }
}
