<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests für ConfigController (Memory Service Check Endpoint).
 */
final class ConfigControllerTest extends WebTestCase
{
    public function testMemoryServiceCheckEndpointIsPublic(): void
    {
        $client = static::createClient();

        // Should be accessible without authentication
        $client->request('GET', '/api/v1/config/memory-service/check');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testMemoryServiceCheckReturnsCorrectStructure(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/config/memory-service/check');

        $this->assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('available', $data);
        $this->assertArrayHasKey('configured', $data);
        $this->assertIsBool($data['available']);
        $this->assertIsBool($data['configured']);
    }

    public function testRuntimeConfigIncludesMemoryServiceFeature(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/config/runtime');

        $this->assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('features', $data);
        $this->assertArrayHasKey('memoryService', $data['features']);
        $this->assertIsBool($data['features']['memoryService']);
    }

    public function testRuntimeConfigIsPublicAndFast(): void
    {
        $client = static::createClient();

        $startTime = microtime(true);
        $client->request('GET', '/api/v1/config/runtime');
        $duration = microtime(true) - $startTime;

        $this->assertResponseIsSuccessful();

        // Should be very fast (no slow health checks)
        $this->assertLessThan(0.5, $duration, 'Runtime config should respond in less than 500ms');
    }
}
