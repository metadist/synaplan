<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Prompt;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\Agent\AgentConfig;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AgentControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
    }

    public function testCreateListGetPatchDelete(): void
    {
        $this->enableFlag();
        $user = $this->createUser('agent-crud@synaplan.internal');
        $this->authenticateClient($this->client, $user);

        $this->postJson('/api/v1/agents', ['name' => 'Contract review']);
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $created = $this->json()['agent'];
        self::assertSame('Contract review', $created['name']);
        self::assertSame('contract-review', $created['slug']);
        self::assertSame('draft', $created['status']);
        self::assertSame('agent.v1', $created['draft']['schema']);
        self::assertGreaterThan(0, $created['promptId']);

        $this->client->request('GET', '/api/v1/agents');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $list = $this->json();
        self::assertTrue($list['success']);
        self::assertNotEmpty($list['agents']);
        self::assertArrayNotHasKey('draft', $list['agents'][0]);

        $id = $created['id'];
        $this->client->request('GET', '/api/v1/agents/'.$id);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame('contract-review', $this->json()['agent']['slug']);

        $this->client->request(
            'PATCH',
            '/api/v1/agents/'.$id,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'description' => 'Reviews NDAs',
                'draft' => ['schema' => 'agent.v1', 'models' => ['chat' => 'anthropic:claude-sonnet-4:chat']],
                'routable' => true,
            ], JSON_THROW_ON_ERROR),
        );
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $updated = $this->json()['agent'];
        self::assertSame('Reviews NDAs', $updated['description']);
        self::assertTrue($updated['routable']);
        self::assertSame('anthropic:claude-sonnet-4:chat', $updated['draft']['models']['chat']);

        $promptId = (int) $created['promptId'];
        $this->client->request('DELETE', '/api/v1/agents/'.$id);
        self::assertSame(Response::HTTP_NO_CONTENT, $this->client->getResponse()->getStatusCode());
        $this->em->clear();
        self::assertNull($this->em->getRepository(Prompt::class)->find($promptId));
    }

    public function testForeignIdIs404(): void
    {
        $this->enableFlag();
        $owner = $this->createUser('agent-owner@synaplan.internal');
        $other = $this->createUser('agent-other@synaplan.internal');
        $this->authenticateClient($this->client, $owner);
        $this->postJson('/api/v1/agents', ['name' => 'Private assistant']);
        $id = $this->json()['agent']['id'];

        $this->authenticateClient($this->client, $other);
        $this->client->request('GET', '/api/v1/agents/'.$id);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testInvalidDraftReturns400WithPath(): void
    {
        $this->enableFlag();
        $user = $this->createUser('agent-bad-draft@synaplan.internal');
        $this->authenticateClient($this->client, $user);
        $this->postJson('/api/v1/agents', ['name' => 'Draft check']);
        $id = $this->json()['agent']['id'];

        $this->client->request(
            'PATCH',
            '/api/v1/agents/'.$id,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['draft' => ['schema' => 'agent.v1', 'tools' => ['foo' => true]]], JSON_THROW_ON_ERROR),
        );
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        $body = $this->json();
        self::assertSame('tools.foo', $body['path']);
        self::assertStringContainsString('Unknown key "tools.foo"', $body['error']);
    }

    public function testCreateRequiresName(): void
    {
        $this->enableFlag();
        $user = $this->createUser('agent-noname@synaplan.internal');
        $this->authenticateClient($this->client, $user);
        $this->postJson('/api/v1/agents', ['description' => 'no name']);
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
    }

    private function enableFlag(): void
    {
        static::getContainer()->get(ConfigRepository::class)
            ->setValue(0, AgentConfig::CONFIG_GROUP, AgentConfig::KEY_ENABLED, '1');
        $this->em->flush();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postJson(string $uri, array $payload): void
    {
        $this->client->request('POST', $uri, server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode($payload));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded, 'Response was not JSON: '.$this->client->getResponse()->getContent());

        return $decoded;
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
            ->setProviderId('agent-rest-'.uniqid())
            ->setUserLevel('NEW');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
