<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ApiKey;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Security\ApiKeyScope;
use App\Service\Iam\IamConfig;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AdminGroupControllerTest extends WebTestCase
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

    public function testRoutesAre404WhenFlagOff(): void
    {
        $this->disableFlag();
        $admin = $this->createAdmin('iam-admin-off@synaplan.internal');
        $this->authenticateClient($this->client, $admin);

        $this->client->request('GET', '/api/v1/admin/groups');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testAdminCreatesGroupAndAddsMembers(): void
    {
        $this->enableFlag();
        $admin = $this->createAdmin('iam-admin-on@synaplan.internal');
        $alice = $this->createUser('iam-alice@synaplan.internal');
        $bob = $this->createUser('iam-bob@synaplan.internal');
        $cara = $this->createUser('iam-cara@synaplan.internal');
        $this->authenticateClient($this->client, $admin);

        $this->postJson('/api/v1/admin/groups', ['name' => 'Sales', 'description' => 'Revenue']);
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
        $group = $this->json()['group'];
        self::assertSame('Sales', $group['name']);
        self::assertSame('sales', $group['slug']);
        $groupId = $group['id'];

        $this->putJson('/api/v1/admin/groups/'.$groupId.'/members/'.$alice->getId(), ['role' => 'manager']);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $this->putJson('/api/v1/admin/groups/'.$groupId.'/members/'.$bob->getId(), ['role' => 'member']);
        $this->putJson('/api/v1/admin/groups/'.$groupId.'/members/'.$cara->getId(), ['role' => 'member']);

        $this->client->request('GET', '/api/v1/admin/groups/'.$groupId.'/members');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertCount(3, $this->json()['members']);
    }

    public function testDirectoryGroupDeleteIs409(): void
    {
        $this->enableFlag();
        $admin = $this->createAdmin('iam-admin-dir@synaplan.internal');
        $group = $this->createDirectoryGroup();

        $this->authenticateClient($this->client, $admin);
        $this->client->request('DELETE', '/api/v1/admin/groups/'.$group->getId());

        self::assertSame(Response::HTTP_CONFLICT, $this->client->getResponse()->getStatusCode());
    }

    public function testDirectoryGroupMemberMutationsAre409(): void
    {
        $this->enableFlag();
        $admin = $this->createAdmin('iam-admin-dir-members@synaplan.internal');
        $alice = $this->createUser('iam-dir-alice@synaplan.internal');
        $group = $this->createDirectoryGroup();
        $this->authenticateClient($this->client, $admin);

        $this->putJson('/api/v1/admin/groups/'.$group->getId().'/members/'.$alice->getId(), [
            'role' => 'member',
        ]);
        self::assertSame(Response::HTTP_CONFLICT, $this->client->getResponse()->getStatusCode());

        $this->client->request('DELETE', '/api/v1/admin/groups/'.$group->getId().'/members/'.$alice->getId());
        self::assertSame(Response::HTTP_CONFLICT, $this->client->getResponse()->getStatusCode());
    }

    public function testLegacyKeyReachesAdminGroups(): void
    {
        $this->enableFlag();
        $admin = $this->createAdmin('iam-admin-legacy-key@synaplan.internal');
        $key = $this->createApiKey($admin, []);

        $this->client->request('GET', '/api/v1/admin/groups', server: [
            'HTTP_X_API_KEY' => $key,
        ]);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    public function testIamReadKeyCannotCreateGroup(): void
    {
        $this->enableFlag();
        $admin = $this->createAdmin('iam-admin-read-key@synaplan.internal');
        $key = $this->createApiKey($admin, [ApiKeyScope::IAM_READ]);

        $this->client->request(
            'POST',
            '/api/v1/admin/groups',
            server: [
                'HTTP_X_API_KEY' => $key,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode(['name' => 'Nope'], JSON_THROW_ON_ERROR),
        );

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    public function testIamManageKeyCanCreateGroup(): void
    {
        $this->enableFlag();
        $admin = $this->createAdmin('iam-admin-manage-key@synaplan.internal');
        $key = $this->createApiKey($admin, [ApiKeyScope::IAM_MANAGE]);

        $this->client->request(
            'POST',
            '/api/v1/admin/groups',
            server: [
                'HTTP_X_API_KEY' => $key,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode(['name' => 'Ops'], JSON_THROW_ON_ERROR),
        );

        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
        self::assertSame('Ops', $this->json()['group']['name']);
    }

    private function enableFlag(): void
    {
        static::getContainer()->get(ConfigRepository::class)
            ->setValue(0, IamConfig::CONFIG_GROUP, IamConfig::KEY_GROUPS_ENABLED, '1');
        $this->em->flush();
    }

    private function disableFlag(): void
    {
        static::getContainer()->get(ConfigRepository::class)
            ->setValue(0, IamConfig::CONFIG_GROUP, IamConfig::KEY_GROUPS_ENABLED, '0');
        $this->em->flush();
    }

    private function createDirectoryGroup(): Group
    {
        $group = new Group();
        $group->setName('From login');
        $group->setSlug('from-login-'.uniqid());
        $group->setKind(Group::KIND_DIRECTORY);
        $this->em->persist($group);
        $this->em->flush();

        return $group;
    }

    private function createAdmin(string $email): User
    {
        $user = $this->createUser($email);
        $user->setUserLevel('ADMIN');
        $this->em->flush();

        return $user;
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
            ->setProviderId('iam-test-'.uniqid())
            ->setUserLevel('NEW');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createApiKey(User $owner, array $scopes): string
    {
        $plain = 'sk_'.bin2hex(random_bytes(16));
        $apiKey = (new ApiKey())
            ->setOwner($owner)
            ->setKey($plain)
            ->setStatus('active')
            ->setName('IAM test key')
            ->setScopes($scopes);
        $this->em->persist($apiKey);
        $this->em->flush();

        return $plain;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postJson(string $uri, array $payload): void
    {
        $this->client->request(
            'POST',
            $uri,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function putJson(string $uri, array $payload): void
    {
        $this->client->request(
            'PUT',
            $uri,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
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
}
