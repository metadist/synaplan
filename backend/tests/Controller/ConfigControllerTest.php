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
        $this->assertArrayHasKey('officeConvertEnabled', $data['features']);
        $this->assertFalse($data['features']['officeConvertEnabled']);
        $this->assertArrayHasKey('documentToolsEnabled', $data['features']);
        $this->assertFalse($data['features']['documentToolsEnabled']);
    }

    /**
     * MOBILE-APP SEAM (App Review 5.1.2(i)): the app names its AI providers on
     * a consent screen that runs before sign-in, so the list has to reach an
     * anonymous client. Losing it here would leave the app disclosing nothing.
     */
    public function testRuntimeConfigNamesTheAiProvidersAnonymously(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/config/runtime');

        $this->assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('aiProviders', $data);
        $this->assertIsArray($data['aiProviders']);

        foreach ($data['aiProviders'] as $provider) {
            $this->assertIsString($provider);
            $this->assertNotSame('', $provider);
        }

        // The test provider serves the default chat model in this environment.
        // It is a fixture, not something a user's input can reach, so a
        // disclosure naming it would be wrong.
        $this->assertNotContains('test', array_map('strtolower', $data['aiProviders']));
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

    public function testRuntimeConfigTellsAnonymousClientsWhetherDemoLoginIsOffered(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/config/runtime');

        $this->assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('setup', $data);
        $this->assertIsArray($data['setup']);
        $this->assertArrayHasKey('demoLoginHint', $data['setup']);
        $this->assertIsBool($data['setup']['demoLoginHint']);
    }

    public function testRuntimeConfigTellsAnonymousClientsWhetherMailCanBeDelivered(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/config/runtime');

        $this->assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('auth', $data);
        $this->assertIsArray($data['auth']);
        $this->assertArrayHasKey('mailerConfigured', $data['auth']);
        $this->assertIsBool($data['auth']['mailerConfigured']);
    }
}
