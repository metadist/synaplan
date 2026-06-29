<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests for the public marketing-news landing endpoint.
 */
final class NewsControllerTest extends WebTestCase
{
    public function testLandingEndpointIsPublic(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/news/landing?lang=en');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testLandingReturnsItemsArray(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/news/landing?lang=en');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('items', $data);
        $this->assertIsArray($data['items']);
    }

    public function testLandingReturnsEmptyWhenMasterSwitchOff(): void
    {
        // The marketing-news master switch is seeded OFF in the test DB, so the
        // endpoint must short-circuit to an empty list without any feed fetch.
        $client = static::createClient();

        $client->request('GET', '/api/v1/news/landing?lang=de');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        $this->assertSame([], $data['items']);
    }

    public function testLandingAcceptsUnknownLocaleGracefully(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/news/landing?lang=zz');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('items', $data);
    }
}
