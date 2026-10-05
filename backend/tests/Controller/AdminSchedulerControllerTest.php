<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminSchedulerControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testNonAdminIsRejected(): void
    {
        $this->loginAs('demo@synaplan.com');

        $this->client->request('GET', '/api/v1/admin/scheduler/status');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousIsRejected(): void
    {
        $this->client->request('GET', '/api/v1/admin/scheduler/status');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAdminGetsAStateOrAnHonestUnavailableAnswer(): void
    {
        $this->loginAs('admin@synaplan.com');

        $this->client->request('GET', '/api/v1/admin/scheduler/status');

        $status = $this->client->getResponse()->getStatusCode();
        self::assertContains($status, [200, 503], 'the test env may run without Redis');
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        if (200 === $status) {
            self::assertContains($payload['state'], ['running', 'stale', 'never']);
            self::assertCount(5, $payload['lanes']);
        } else {
            self::assertSame('Background job status is unavailable', $payload['error']);
        }
    }

    private function loginAs(string $mail): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['mail' => $mail]);
        if (!$user) {
            self::markTestSkipped("Fixture user $mail not found. Run fixtures first.");
        }

        $this->authenticateClient($this->client, $user);
    }
}
