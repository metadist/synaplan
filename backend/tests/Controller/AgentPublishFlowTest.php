<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Group;
use App\Entity\GroupMember;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\Agent\AgentConfig;
use App\Service\Iam\IamConfig;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AgentPublishFlowTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $config = static::getContainer()->get(ConfigRepository::class);
        $config->setValue(0, AgentConfig::CONFIG_GROUP, AgentConfig::KEY_ENABLED, '1');
        $config->setValue(0, IamConfig::CONFIG_GROUP, IamConfig::KEY_GROUPS_ENABLED, '1');
        $config->setValue(0, IamConfig::CONFIG_GROUP, IamConfig::KEY_SHARING_ENABLED, '1');
        $this->em->flush();
    }

    public function testPublishShareGalleryCloneAndArchive(): void
    {
        $owner = $this->createUser('agent-pub-owner@synaplan.internal');
        $member = $this->createUser('agent-pub-member@synaplan.internal');
        $outsider = $this->createUser('agent-pub-out@synaplan.internal');
        $admin = $this->createUser('agent-pub-admin@synaplan.internal');
        $admin->setUserLevel('ADMIN');
        $this->em->flush();

        $group = new Group();
        $group->setName('Legal');
        $group->setSlug('legal-'.uniqid());
        $this->em->persist($group);
        $this->em->flush();
        $this->em->persist(new GroupMember((int) $group->getId(), (int) $member->getId()));
        $this->em->flush();

        $this->authenticateClient($this->client, $owner);
        $this->postJson('/api/v1/agents', ['name' => 'Contract review']);
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $id = (int) $this->json()['agent']['id'];

        $this->postJson('/api/v1/agents/'.$id.'/publish', ['changelog' => 'First published cut']);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame(1, $this->json()['version']['version']);

        $this->postJson('/api/v1/agents/'.$id.'/publish', ['changelog' => 'unchanged']);
        self::assertSame(Response::HTTP_CONFLICT, $this->client->getResponse()->getStatusCode());
        self::assertSame('nothing_changed', $this->json()['error']);

        $this->client->request(
            'PATCH',
            '/api/v1/agents/'.$id,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['description' => 'Now stricter'], JSON_THROW_ON_ERROR),
        );
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $this->postJson('/api/v1/shares', [
            'kind' => 'agent',
            'resource' => (string) $id,
            'subjectType' => 'group',
            'subjectId' => (int) $group->getId(),
            'permission' => 'use',
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $this->authenticateClient($this->client, $member);
        $this->client->request('GET', '/api/v1/agents/gallery');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $shared = array_values(array_filter($this->json()['cards'], static fn (array $c): bool => 'Contract review' === ($c['name'] ?? null)));
        self::assertCount(1, $shared);
        self::assertSame('shared', $shared[0]['origin']);
        self::assertSame(1, $shared[0]['version']);
        self::assertArrayNotHasKey('draft', $shared[0]);
        self::assertFalse($shared[0]['canEdit']);
        self::assertTrue($shared[0]['canStartChat']);

        $this->client->request('GET', '/api/v1/agents/'.$id);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertArrayNotHasKey('draft', $this->json()['agent']);

        $this->client->request('POST', '/api/v1/agents/'.$id.'/clone');
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $clone = $this->json()['agent'];
        self::assertSame('draft', $clone['status']);
        self::assertSame($id, $clone['parentId']);
        self::assertSame('Now stricter', $clone['description']);

        $this->authenticateClient($this->client, $outsider);
        $this->client->request('GET', '/api/v1/agents/'.$id);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', '/api/v1/agents/'.$id.'/clone');
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());

        $this->authenticateClient($this->client, $admin);
        $this->client->request('GET', '/api/v1/agents/'.$id);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());

        $this->authenticateClient($this->client, $owner);
        $this->client->request(
            'PATCH',
            '/api/v1/agents/'.$id,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['status' => 'archived'], JSON_THROW_ON_ERROR),
        );
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame('archived', $this->json()['agent']['status']);

        $this->authenticateClient($this->client, $member);
        $this->client->request('GET', '/api/v1/agents/gallery');
        foreach ($this->json()['cards'] as $card) {
            self::assertNotSame('Contract review', $card['name']);
        }

        $this->authenticateClient($this->client, $owner);
        $this->client->request('DELETE', '/api/v1/agents/'.$id);
        self::assertSame(Response::HTTP_NO_CONTENT, $this->client->getResponse()->getStatusCode());
    }

    public function testDraftCannotBeShared(): void
    {
        $owner = $this->createUser('agent-draft-share@synaplan.internal');
        $this->authenticateClient($this->client, $owner);
        $this->postJson('/api/v1/agents', ['name' => 'Unpublished']);
        $id = (int) $this->json()['agent']['id'];

        $other = $this->createUser('agent-draft-target@synaplan.internal');
        $this->postJson('/api/v1/shares', [
            'kind' => 'agent',
            'resource' => (string) $id,
            'subjectType' => 'user',
            'subjectId' => (int) $other->getId(),
            'permission' => 'use',
        ]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
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
            ->setProviderId('agent-pub-'.uniqid())
            ->setUserLevel('NEW');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
