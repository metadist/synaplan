<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ApiKey;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class HealthMonitorControllerTest extends WebTestCase
{
    private const API_KEY = 'sk_test_health_monitor_key_1234567890abcdef';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();

        $this->createMonitorUserWithApiKey();
    }

    public function testReturns401WithoutApiKey(): void
    {
        $this->client->request('GET', '/api/health/probe');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }

    public function testReturns401WithInvalidApiKey(): void
    {
        $this->client->request(
            'GET',
            '/api/health/probe',
            [],
            [],
            ['HTTP_X_API_KEY' => 'sk_invalid_key'],
        );

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }

    public function testReturnsOkWithValidApiKey(): void
    {
        $this->client->request(
            'GET',
            '/api/health/probe',
            [],
            [],
            ['HTTP_X_API_KEY' => self::API_KEY],
        );

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('STATUS:OK', (string) $this->client->getResponse()->getContent());
    }

    public function testReturnsErrorWhenEmailNotVerified(): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['mail' => 'health-test@synaplan.internal']);
        $user->setEmailVerified(false);
        $this->em->flush();

        $this->client->request(
            'GET',
            '/api/health/probe',
            [],
            [],
            ['HTTP_X_API_KEY' => self::API_KEY],
        );

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('STATUS:ERROR', (string) $this->client->getResponse()->getContent());
    }

    public function testResponseLeaksNoDetails(): void
    {
        $this->client->request(
            'GET',
            '/api/health/probe',
            [],
            [],
            ['HTTP_X_API_KEY' => self::API_KEY],
        );

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertCount(1, $payload);
        self::assertArrayHasKey('status', $payload);
        self::assertContains($payload['status'], ['STATUS:OK', 'STATUS:ERROR']);
    }

    private function createMonitorUserWithApiKey(): void
    {
        $userRepo = $this->em->getRepository(User::class);
        $user = $userRepo->findOneBy(['mail' => 'health-test@synaplan.internal']);

        if (null === $user) {
            $user = new User();
            $user->setMail('health-test@synaplan.internal');
            $user->setCreated(date('YmdHis'));
            $user->setType('WEB');
            $user->setProviderId('health-monitor-test');
            $user->setUserLevel('NEW');
            $user->setEmailVerified(true);
            $this->em->persist($user);
            $this->em->flush();
        } else {
            $user->setEmailVerified(true);
            $this->em->flush();
        }

        $apiKeyRepo = $this->em->getRepository(ApiKey::class);
        $existingKey = $apiKeyRepo->findOneBy(['key' => self::API_KEY]);

        if (null === $existingKey) {
            $apiKey = new ApiKey();
            $apiKey->setOwner($user);
            $apiKey->setKey(self::API_KEY);
            $apiKey->setStatus('active');
            $apiKey->setName('Health Monitor Test');
            $apiKey->setScopes([]);
            $this->em->persist($apiKey);
            $this->em->flush();
        }
    }
}
