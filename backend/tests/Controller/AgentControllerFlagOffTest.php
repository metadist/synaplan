<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\Agent\AgentConfig;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AgentControllerFlagOffTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        static::getContainer()->get(ConfigRepository::class)
            ->setValue(0, AgentConfig::CONFIG_GROUP, AgentConfig::KEY_ENABLED, '0');
        $this->em->flush();
    }

    public function testListIs404WhenFlagOff(): void
    {
        $user = $this->createUser('agent-off-list@synaplan.internal');
        $this->authenticateClient($this->client, $user);
        $this->client->request('GET', '/api/v1/agents');
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateIs404WhenFlagOff(): void
    {
        $user = $this->createUser('agent-off-create@synaplan.internal');
        $this->authenticateClient($this->client, $user);
        $this->client->request(
            'POST',
            '/api/v1/agents',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['name' => 'Should not exist']),
        );
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testGetPatchDeleteAre404WhenFlagOff(): void
    {
        $user = $this->createUser('agent-off-rest@synaplan.internal');
        $this->authenticateClient($this->client, $user);

        $this->client->request('GET', '/api/v1/agents/1');
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());

        $this->client->request(
            'PATCH',
            '/api/v1/agents/1',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['name' => 'Nope']),
        );
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());

        $this->client->request('DELETE', '/api/v1/agents/1');
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/v1/agents/gallery');
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/api/v1/agents/1/clone');
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());

        $this->client->request(
            'POST',
            '/api/v1/agents/1/publish',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['changelog' => 'nope']),
        );
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/v1/agents/1/versions');
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/v1/agents/1/usage');
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testUnauthenticatedIs401Not404(): void
    {
        $this->client->request('GET', '/api/v1/agents');
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }

    private function createUser(string $email): User
    {
        $existing = $this->em->getRepository(User::class)->findOneBy(['mail' => $email]);
        if ($existing instanceof User) {
            return $existing;
        }

        $user = (new User())
            ->setMail($email)
            ->setType('WEB')
            ->setProviderId('agent-off-'.uniqid())
            ->setUserLevel('NEW');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
