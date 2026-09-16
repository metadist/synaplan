<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Group;
use App\Entity\GroupMember;
use App\Entity\Prompt;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\Iam\IamConfig;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class PromptControllerSharingTest extends WebTestCase
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

    public function testGetOmitsIamFieldsWhenSharingOff(): void
    {
        $this->disableFlags();
        $owner = $this->createUser('prompt-off@synaplan.internal');
        $prompt = $this->createPrompt((int) $owner->getId(), 'off-helper', 'Off Helper');
        $this->authenticateClient($this->client, $owner);

        $this->client->request('GET', '/api/v1/prompts/'.$prompt->getId());

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $body = $this->json();
        self::assertArrayNotHasKey('shared', $body['prompt']);
        self::assertArrayNotHasKey('access', $body['prompt']);
        self::assertArrayNotHasKey('owner', $body['prompt']);
    }

    public function testOwnerCanGetOwnPrompt(): void
    {
        $this->enableSharing();
        $owner = $this->createUser('prompt-owner@synaplan.internal');
        $prompt = $this->createPrompt((int) $owner->getId(), 'owner-helper', 'Owner Helper');
        $this->authenticateClient($this->client, $owner);

        $this->client->request('GET', '/api/v1/prompts/'.$prompt->getId());

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame('owner', $this->json()['prompt']['access']);
    }

    public function testForeignPromptIs403WithoutShare(): void
    {
        $this->enableSharing();
        $owner = $this->createUser('prompt-priv-owner@synaplan.internal');
        $other = $this->createUser('prompt-priv-other@synaplan.internal');
        $prompt = $this->createPrompt((int) $owner->getId(), 'private-helper', 'Private Helper');
        $this->authenticateClient($this->client, $other);

        $this->client->request('GET', '/api/v1/prompts/'.$prompt->getId());

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    public function testAdminCannotReadForeignPromptWithoutShare(): void
    {
        $this->enableSharing();
        $owner = $this->createUser('prompt-c8-owner@synaplan.internal');
        $admin = $this->createAdmin('prompt-c8-admin@synaplan.internal');
        $prompt = $this->createPrompt((int) $owner->getId(), 'c8-helper', 'C8 Helper');
        $this->authenticateClient($this->client, $admin);

        $this->client->request('GET', '/api/v1/prompts/'.$prompt->getId());

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    public function testUseShareLetsMemberGetAndListAssistant(): void
    {
        $this->enableSharing();
        $owner = $this->createUser('prompt-share-owner@synaplan.internal');
        $member = $this->createUser('prompt-share-member@synaplan.internal');
        $group = $this->createGroup('Sales');
        $this->addMember($group, (int) $member->getId());
        $prompt = $this->createPrompt((int) $owner->getId(), 'sales-helper', 'Sales Helper');

        $this->authenticateClient($this->client, $owner);
        $this->postJson('/api/v1/shares', [
            'kind' => 'assistant',
            'resource' => (string) $prompt->getId(),
            'subjectType' => 'group',
            'subjectId' => (int) $group->getId(),
            'permission' => 'use',
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());

        $this->authenticateClient($this->client, $member);
        $this->client->request('GET', '/api/v1/prompts/'.$prompt->getId());
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $body = $this->json();
        self::assertTrue($body['prompt']['shared']);
        self::assertSame('use', $body['prompt']['access']);

        $this->client->request('GET', '/api/v1/prompts');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $topics = array_column($this->json()['prompts'], 'topic');
        self::assertContains('sales-helper', $topics);
    }

    public function testSystemPromptCannotBeShared(): void
    {
        $this->enableSharing();
        $owner = $this->createUser('prompt-system-share@synaplan.internal');
        $system = $this->em->getRepository(Prompt::class)->findOneBy(['ownerId' => 0, 'topic' => 'general']);
        if (!$system instanceof Prompt) {
            $system = $this->createPrompt(0, 'general', 'General');
        }
        $this->authenticateClient($this->client, $owner);

        $this->postJson('/api/v1/shares', [
            'kind' => 'assistant',
            'resource' => (string) $system->getId(),
            'subjectType' => 'everyone',
            'subjectId' => 0,
            'permission' => 'use',
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
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
            ->setProviderId('prompt-share-'.uniqid())
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

    private function createPrompt(int $ownerId, string $topic, string $name): Prompt
    {
        $existing = $this->em->getRepository(Prompt::class)->findOneBy([
            'ownerId' => $ownerId,
            'topic' => $topic,
        ]);
        if ($existing instanceof Prompt) {
            return $existing;
        }

        $prompt = new Prompt();
        $prompt->setOwnerId($ownerId);
        $prompt->setLanguage('en');
        $prompt->setTopic($topic);
        $prompt->setShortDescription($name);
        $prompt->setPrompt('You help with '.$name.'.');
        $this->em->persist($prompt);
        $this->em->flush();

        return $prompt;
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
