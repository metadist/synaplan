<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration tests for HealthController.
 */
class HealthControllerTest extends WebTestCase
{
    private $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testHealthEndpointReturnsOk(): void
    {
        $this->client->request('GET', '/api/health');

        $this->assertResponseIsSuccessful();
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);

        $responseData = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('status', $responseData);
        $this->assertEquals('ok', $responseData['status']);

        $this->assertArrayHasKey('timestamp', $responseData);
        $this->assertIsInt($responseData['timestamp']);

        $this->assertArrayHasKey('providers', $responseData);
        $this->assertIsArray($responseData['providers']);
    }

    public function testHealthEndpointReturnsProviderStatus(): void
    {
        $this->client->request('GET', '/api/health');

        $responseData = json_decode($this->client->getResponse()->getContent(), true);

        $providers = $responseData['providers'];

        // Should have at least one provider
        $this->assertNotEmpty($providers);

        // Each provider should have status information
        foreach ($providers as $providerName => $status) {
            $this->assertIsString($providerName);
            $this->assertIsArray($status);

            // Status should contain availability info
            if (isset($status['available'])) {
                $this->assertIsBool($status['available']);
            }
        }
    }

    public function testHealthEndpointDoesNotRequireAuth(): void
    {
        // Health endpoint should be public
        $this->client->request('GET', '/api/health');

        $this->assertResponseIsSuccessful();
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    public function testHealthEndpointReturnsJsonContentType(): void
    {
        $this->client->request('GET', '/api/health');

        $this->assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function testHealthEndpointOnlyAcceptsGetMethod(): void
    {
        $this->client->request('POST', '/api/health');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);

        $this->client->request('PUT', '/api/health');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);

        $this->client->request('DELETE', '/api/health');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }
}
