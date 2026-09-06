<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Prompt;
use App\Entity\SavedTask;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\Iam\IamConfig;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class SavedTaskControllerTest extends WebTestCase
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

    public function testForeignTaskIs404(): void
    {
        $this->enableSharing();
        $owner = $this->createUser('task-priv-owner@synaplan.internal');
        $other = $this->createUser('task-priv-other@synaplan.internal');
        $prompt = $this->createPrompt((int) $owner->getId(), 'task-priv', 'Task Priv');
        $task = $this->createTask((int) $owner->getId(), (int) $prompt->getId(), 'Private task');
        $this->authenticateClient($this->client, $other);

        $this->client->request('GET', '/api/v1/saved-tasks/'.$task->getId().'/runs');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testAdminCannotSeeForeignRuns(): void
    {
        $this->enableSharing();
        $owner = $this->createUser('task-c8-owner@synaplan.internal');
        $admin = $this->createAdmin('task-c8-admin@synaplan.internal');
        $prompt = $this->createPrompt((int) $owner->getId(), 'task-c8', 'Task C8');
        $task = $this->createTask((int) $owner->getId(), (int) $prompt->getId(), 'C8 task');
        $this->authenticateClient($this->client, $admin);

        $this->client->request('GET', '/api/v1/saved-tasks/'.$task->getId().'/runs');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testCopyCreatesManualOwnedTask(): void
    {
        $this->enableSharing();
        $owner = $this->createUser('task-copy-owner@synaplan.internal');
        $member = $this->createUser('task-copy-member@synaplan.internal');
        $prompt = $this->createPrompt((int) $owner->getId(), 'task-copy-asst', 'Copy Assistant');
        $task = $this->createTask((int) $owner->getId(), (int) $prompt->getId(), 'Weekly report');
        $task->setTrigger(SavedTask::TRIGGER_SCHEDULE, ['kind' => 'daily', 'at' => '07:00']);
        $task->setAllowUnattended(true);
        $this->em->flush();

        $this->authenticateClient($this->client, $owner);
        $this->postJson('/api/v1/shares', [
            'kind' => 'saved_task',
            'resource' => (string) $task->getId(),
            'subjectType' => 'user',
            'subjectId' => (int) $member->getId(),
            'permission' => 'use',
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
        $this->postJson('/api/v1/shares', [
            'kind' => 'assistant',
            'resource' => (string) $prompt->getId(),
            'subjectType' => 'user',
            'subjectId' => (int) $member->getId(),
            'permission' => 'use',
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());

        $this->authenticateClient($this->client, $member);
        $this->client->request('POST', '/api/v1/saved-tasks/'.$task->getId().'/copy');
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
        $copy = $this->json()['task'];
        self::assertNotSame($task->getId(), $copy['id']);
        self::assertSame('manual', $copy['triggerType']);
        self::assertFalse($copy['allowUnattended']);
        self::assertNull($copy['chatId']);
        self::assertSame((int) $prompt->getId(), $copy['promptId']);
    }

    public function testCopyWithoutAssistantAccessIs409(): void
    {
        $this->enableSharing();
        $owner = $this->createUser('task-409-owner@synaplan.internal');
        $member = $this->createUser('task-409-member@synaplan.internal');
        $prompt = $this->createPrompt((int) $owner->getId(), 'task-409-asst', 'Hidden Assistant');
        $task = $this->createTask((int) $owner->getId(), (int) $prompt->getId(), 'Needs assistant');

        $this->authenticateClient($this->client, $owner);
        $this->postJson('/api/v1/shares', [
            'kind' => 'saved_task',
            'resource' => (string) $task->getId(),
            'subjectType' => 'user',
            'subjectId' => (int) $member->getId(),
            'permission' => 'use',
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());

        $this->authenticateClient($this->client, $member);
        $this->client->request('POST', '/api/v1/saved-tasks/'.$task->getId().'/copy');

        self::assertSame(Response::HTTP_CONFLICT, $this->client->getResponse()->getStatusCode());
        self::assertSame('iam.assistantNotShared', $this->json()['error']);
    }

    private function enableSharing(): void
    {
        $config = static::getContainer()->get(ConfigRepository::class);
        $config->setValue(0, IamConfig::CONFIG_GROUP, IamConfig::KEY_GROUPS_ENABLED, '1');
        $config->setValue(0, IamConfig::CONFIG_GROUP, IamConfig::KEY_SHARING_ENABLED, '1');
        $config->setValue(0, \App\Service\SavedTask\SavedTaskConfig::CONFIG_GROUP, \App\Service\SavedTask\SavedTaskConfig::KEY_ENABLED, '1');
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
            ->setProviderId('task-share-'.uniqid())
            ->setUserLevel('NEW');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
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

    private function createTask(int $ownerId, int $promptId, string $name): SavedTask
    {
        $task = new SavedTask($ownerId, $promptId, $name);
        $this->em->persist($task);
        $this->em->flush();

        return $task;
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
