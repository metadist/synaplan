<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class HealthMonitorControllerTest extends WebTestCase
{
    private const TOKEN = 'test-monitor-token';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testReturns401WithoutToken(): void
    {
        $this->client->request('GET', '/api/health/login');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('STATUS:ERROR', (string) $this->client->getResponse()->getContent());
    }

    public function testReturns401WithWrongToken(): void
    {
        $this->client->request(
            'GET',
            '/api/health/login',
            [],
            [],
            ['HTTP_X_HEALTH_MONITOR_TOKEN' => 'wrong'],
        );

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }

    public function testAcceptsTokenViaHeader(): void
    {
        $this->client->request(
            'GET',
            '/api/health/login',
            [],
            [],
            ['HTTP_X_HEALTH_MONITOR_TOKEN' => self::TOKEN],
        );

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $this->client->getResponse()->getStatusCode());
    }

    public function testAcceptsTokenViaQueryParam(): void
    {
        $this->client->request('GET', '/api/health/login?monitor='.self::TOKEN);

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $this->client->getResponse()->getStatusCode());
    }

    public function testResponseLeaksNoDetails(): void
    {
        $this->client->request(
            'GET',
            '/api/health/login',
            [],
            [],
            ['HTTP_X_HEALTH_MONITOR_TOKEN' => self::TOKEN],
        );

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertCount(1, $payload);
        self::assertArrayHasKey('status', $payload);
        self::assertContains($payload['status'], ['STATUS:OK', 'STATUS:ERROR']);
    }
}
