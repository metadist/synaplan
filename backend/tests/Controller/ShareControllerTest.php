<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ApiKey;
use App\Entity\Chat;
use App\Entity\File;
use App\Entity\Group;
use App\Entity\GroupMember;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Security\ApiKeyScope;
use App\Service\Iam\IamConfig;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ShareControllerTest extends WebTestCase
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

    public function testRoutesAre404WhenSharingOff(): void
    {
        $this->disableFlags();
        $owner = $this->createUser('share-off@synaplan.internal');
        $this->authenticateClient($this->client, $owner);

        $this->client->request('GET', '/api/v1/me/shared?kind=conversation');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testOwnerSharesConversationAndMemberCanRead(): void
    {
        $this->enableSharing();
        $owner = $this->createUser('share-owner@synaplan.internal');
        $member = $this->createUser('share-member@synaplan.internal');
        $group = $this->createGroup('Sales');
        $this->addMember($group, (int) $member->getId());
        $chat = $this->createChat((int) $owner->getId(), 'Q3 playbook');

        $this->authenticateClient($this->client, $owner);
        $this->postJson('/api/v1/shares', [
            'kind' => 'conversation',
            'resource' => (string) $chat->getId(),
            'subjectType' => 'group',
            'subjectId' => (int) $group->getId(),
            'permission' => 'use',
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());

        $this->authenticateClient($this->client, $member);
        $this->client->request('GET', '/api/v1/chats/'.$chat->getId());
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $body = $this->json();
        self::assertSame('use', $body['chat']['access']);
        self::assertSame((int) $owner->getId(), $body['chat']['owner']['id']);

        $this->client->request('GET', '/api/v1/me/shared?kind=conversation');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertNotEmpty($this->json()['items']);
    }

    public function testConversationEditIs422(): void
    {
        $this->enableSharing();
        $owner = $this->createUser('share-edit@synaplan.internal');
        $chat = $this->createChat((int) $owner->getId(), 'No edit');
        $this->authenticateClient($this->client, $owner);

        $this->postJson('/api/v1/shares', [
            'kind' => 'conversation',
            'resource' => (string) $chat->getId(),
            'subjectType' => 'everyone',
            'subjectId' => 0,
            'permission' => 'edit',
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
    }

    public function testAdminCannotReadForeignChatWithoutShare(): void
    {
        $this->enableSharing();
        $owner = $this->createUser('share-c8-owner@synaplan.internal');
        $admin = $this->createAdmin('share-c8-admin@synaplan.internal');
        $chat = $this->createChat((int) $owner->getId(), 'Private');

        $this->authenticateClient($this->client, $admin);
        $this->client->request('GET', '/api/v1/chats/'.$chat->getId());

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    public function testAdminCannotDownloadForeignFileWithoutShare(): void
    {
        $this->enableSharing();
        $owner = $this->createUser('share-c8-file-owner@synaplan.internal');
        $admin = $this->createAdmin('share-c8-file-admin@synaplan.internal');
        $file = (new File())
            ->setUserId((int) $owner->getId())
            ->setFilePath('c8-private.txt')
            ->setFileType('txt')
            ->setFileName('c8-private.txt')
            ->setFileSize(4)
            ->setFileMime('text/plain')
            ->setFileText('secret')
            ->setStatus('processed');
        $this->em->persist($file);
        $this->em->flush();

        $this->authenticateClient($this->client, $admin);
        $this->client->request('GET', '/api/v1/files/'.$file->getId().'/download');

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    public function testContinueCreatesOwnerCopy(): void
    {
        $this->enableSharing();
        $owner = $this->createUser('share-copy-owner@synaplan.internal');
        $member = $this->createUser('share-copy-member@synaplan.internal');
        $chat = $this->createChat((int) $owner->getId(), 'Continue me');
        $this->authenticateClient($this->client, $owner);
        $this->postJson('/api/v1/shares', [
            'kind' => 'conversation',
            'resource' => (string) $chat->getId(),
            'subjectType' => 'user',
            'subjectId' => (int) $member->getId(),
            'permission' => 'use',
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());

        $this->authenticateClient($this->client, $member);
        $this->client->request('POST', '/api/v1/chats/'.$chat->getId().'/continue');
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
        $copy = $this->json()['chat'];
        self::assertSame('owner', $copy['access']);
        self::assertNotSame($chat->getId(), $copy['id']);
    }

    public function testLegacyKeyReachesShares(): void
    {
        $this->enableSharing();
        $owner = $this->createUser('share-legacy-key@synaplan.internal');
        $chat = $this->createChat((int) $owner->getId(), 'Key chat');
        $key = $this->createApiKey($owner, []);

        $this->client->request(
            'GET',
            '/api/v1/shares?kind=conversation&resource='.$chat->getId(),
            server: ['HTTP_X_API_KEY' => $key],
        );

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    public function testIamReadKeyCannotGrantShare(): void
    {
        $this->enableSharing();
        $owner = $this->createUser('share-read-key@synaplan.internal');
        $chat = $this->createChat((int) $owner->getId(), 'Scoped');
        $key = $this->createApiKey($owner, [ApiKeyScope::IAM_READ]);

        $this->client->request(
            'POST',
            '/api/v1/shares',
            server: [
                'HTTP_X_API_KEY' => $key,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode([
                'kind' => 'conversation',
                'resource' => (string) $chat->getId(),
                'subjectType' => 'everyone',
                'permission' => 'read',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    private function enableSharing(): void
    {
        $config = static::getContainer()->get(ConfigRepository::class);
        $config->setValue(0, IamConfig::CONFIG_GROUP, IamConfig::KEY_GROUPS_ENABLED, '1');
        $config->setValue(0, IamConfig::CONFIG_GROUP, IamConfig::KEY_SHARING_ENABLED, '1');
        $this->em->flush();
    }

    private function disableFlags(): void
    {
        $config = static::getContainer()->get(ConfigRepository::class);
        $config->setValue(0, IamConfig::CONFIG_GROUP, IamConfig::KEY_GROUPS_ENABLED, '0');
        $config->setValue(0, IamConfig::CONFIG_GROUP, IamConfig::KEY_SHARING_ENABLED, '0');
        $this->em->flush();
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
            ->setProviderId('share-test-'.uniqid())
            ->setUserLevel('NEW');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createGroup(string $name): Group
    {
        $group = new Group();
        $group->setName($name);
        $group->setSlug(strtolower($name).'-'.uniqid());
        $this->em->persist($group);
        $this->em->flush();

        return $group;
    }

    private function addMember(Group $group, int $userId): void
    {
        $member = new GroupMember((int) $group->getId(), $userId);
        $this->em->persist($member);
        $this->em->flush();
    }

    private function createChat(int $userId, string $title): Chat
    {
        $chat = new Chat();
        $chat->setUserId($userId);
        $chat->setTitle($title);
        $this->em->persist($chat);
        $this->em->flush();

        return $chat;
    }

    private function createApiKey(User $owner, array $scopes): string
    {
        $plain = 'sk_'.bin2hex(random_bytes(16));
        $apiKey = (new ApiKey())
            ->setOwner($owner)
            ->setKey($plain)
            ->setStatus('active')
            ->setName('Share test key')
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
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded, 'Response was not JSON: '.$this->client->getResponse()->getContent());

        return $decoded;
    }
}
