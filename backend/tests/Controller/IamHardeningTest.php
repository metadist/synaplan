<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Chat;
use App\Entity\Group;
use App\Entity\GroupMember;
use App\Entity\Prompt;
use App\Entity\User;
use App\Entity\Widget;
use App\Repository\ConfigRepository;
use App\Repository\PromptRepository;
use App\Service\Iam\IamConfig;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Regression tests for the findings of the 2026-09-07 IAM security review.
 * Each test names the hole it closes; all of them are only reachable with
 * sharing switched on, which is why they must stay green before the flag is.
 */
final class IamHardeningTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->enableSharing();
    }

    /**
     * H1 — the people picker used to run a substring LIKE over the whole
     * BUSERDETAILS JSON, which made phone numbers, provider subjects and the
     * pending phone-verification code guessable letter by letter.
     */
    public function testPeoplePickerMatchesNamesAndEmailOnly(): void
    {
        $searcher = $this->createUser('hardening-searcher@synaplan.internal');
        $target = $this->createUser('hardening-target@synaplan.internal');
        $target->setUserDetails([
            'full_name' => 'Hanna Probe',
            'phone' => '+491701234567',
            'phone_verification' => ['code' => 'ZQ7XK'],
            'oidc_sub' => 'sub-zq7xk-secret',
        ]);
        $this->em->flush();
        $this->authenticateClient($this->client, $searcher);

        $this->client->request('GET', '/api/v1/iam/subjects?q=Hanna');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertContains((int) $target->getId(), $this->userIds(), 'name fields stay searchable');

        $this->client->request('GET', '/api/v1/iam/subjects?q=hardening-target');
        self::assertContains((int) $target->getId(), $this->userIds(), 'email stays searchable');

        foreach (['ZQ7XK', '49170', 'zq7xk-secret', '"code"'] as $probe) {
            $this->client->request('GET', '/api/v1/iam/subjects?q='.urlencode($probe));
            self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
            self::assertNotContains(
                (int) $target->getId(),
                $this->userIds(),
                sprintf('probe "%s" must not find a user through non-name JSON fields', $probe),
            );
        }

        $this->client->request('GET', '/api/v1/iam/subjects?q=H');
        self::assertSame([], $this->userIds(), 'a single character is not a lookup');
    }

    /**
     * H2 — a shared prompt whose topic collides with a system topic used to
     * win over the system prompt for every recipient, and an owner could
     * rename a prompt into the reserved `tools:` namespace after creating it.
     */
    public function testSharedPromptCannotShadowSystemOrToolsTopics(): void
    {
        $attacker = $this->createUser('hardening-attacker@synaplan.internal');
        $victim = $this->createUser('hardening-victim@synaplan.internal');
        $systemTopic = 'hardening-system-'.uniqid();
        $system = $this->createPrompt(0, $systemTopic, 'System helper');
        $shadow = $this->createPrompt((int) $attacker->getId(), $systemTopic, 'Shadow helper');
        $harmless = $this->createPrompt((int) $attacker->getId(), 'hardening-shared-'.uniqid(), 'Shared helper');

        $this->authenticateClient($this->client, $attacker);
        foreach ([$shadow, $harmless] as $prompt) {
            $this->postJson('/api/v1/shares', [
                'kind' => 'assistant',
                'resource' => (string) $prompt->getId(),
                'subjectType' => 'user',
                'subjectId' => (int) $victim->getId(),
                'permission' => 'use',
            ]);
            self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
        }

        $repo = static::getContainer()->get(PromptRepository::class);
        $resolved = $repo->findByTopicAndUser($systemTopic, (int) $victim->getId());
        self::assertInstanceOf(Prompt::class, $resolved);
        self::assertSame($system->getId(), $resolved->getId(), 'the system prompt wins over a shared one with the same topic');

        $sharedTopics = array_map(
            static fn (Prompt $p): string => $p->getTopic(),
            array_filter($repo->findAllForUser((int) $victim->getId()), static fn (Prompt $p): bool => $p->getOwnerId() === (int) $attacker->getId()),
        );
        self::assertContains($harmless->getTopic(), $sharedTopics, 'a share on a fresh topic still reaches the recipient');
        self::assertNotContains($systemTopic, $sharedTopics, 'a share on a system topic is dropped for the recipient');

        $this->client->request(
            'PUT',
            '/api/v1/prompts/'.$harmless->getId(),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['topic' => 'tools:sort'], JSON_THROW_ON_ERROR),
        );
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        $fresh = static::getContainer()->get('doctrine')->getManager();
        $fresh->clear();
        $reloaded = $fresh->find(Prompt::class, $harmless->getId());
        self::assertInstanceOf(Prompt::class, $reloaded);
        self::assertStringStartsNotWith('tools:', $reloaded->getTopic());
    }

    /**
     * H3 — an administrator manages shares without holding one, and could use
     * that to grant a group they belong to (or everyone) read on any private
     * item, bypassing the impersonation switch.
     */
    public function testAdminCannotGrantThemselvesAccessThroughAGroupOrEveryone(): void
    {
        $owner = $this->createUser('hardening-owner@synaplan.internal');
        $admin = $this->createAdmin('hardening-admin@synaplan.internal');
        $ownGroup = $this->createGroup('Admins circle');
        $this->addMember($ownGroup, (int) $admin->getId());
        $otherGroup = $this->createGroup('Sales floor');
        $chat = $this->createChat((int) $owner->getId(), 'Board minutes');

        $this->authenticateClient($this->client, $admin);

        foreach ([
            ['subjectType' => 'group', 'subjectId' => (int) $ownGroup->getId()],
            ['subjectType' => 'everyone', 'subjectId' => 0],
            ['subjectType' => 'user', 'subjectId' => (int) $admin->getId()],
        ] as $subject) {
            $this->postJson('/api/v1/shares', [
                'kind' => 'conversation',
                'resource' => (string) $chat->getId(),
                'permission' => 'read',
            ] + $subject);
            self::assertContains(
                $this->client->getResponse()->getStatusCode(),
                [Response::HTTP_FORBIDDEN, Response::HTTP_BAD_REQUEST],
                sprintf('subject %s must be refused for a share-less admin', $subject['subjectType']),
            );

            $this->client->request('GET', '/api/v1/chats/'.$chat->getId());
            self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode(), 'admin still cannot read the chat');
        }

        $this->postJson('/api/v1/shares', [
            'kind' => 'conversation',
            'resource' => (string) $chat->getId(),
            'subjectType' => 'group',
            'subjectId' => (int) $otherGroup->getId(),
            'permission' => 'read',
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode(), 'sharing on the owner\'s behalf with a group the admin is not in stays allowed');
    }

    /**
     * M2 — a widget shared for reading returned the Slack webhook URL and the
     * external API token verbatim.
     */
    public function testWidgetShareHidesCredentials(): void
    {
        $owner = $this->createUser('hardening-widget-owner@synaplan.internal');
        $reader = $this->createUser('hardening-widget-reader@synaplan.internal');
        $widget = new Widget();
        $widget->setOwnerId((int) $owner->getId());
        $widget->setOwner($owner);
        $widget->setName('Support');
        $widget->setTaskPromptTopic('general');
        $widget->setConfig([
            'slackWebhookUrl' => 'https://hooks.slack.com/services/T000/B000/secret',
            'externalApiToken' => 'tok-secret',
            'primaryColor' => '#123456',
        ]);
        $this->em->persist($widget);
        $this->em->flush();

        $this->authenticateClient($this->client, $owner);
        $this->postJson('/api/v1/shares', [
            'kind' => 'widget',
            'resource' => (string) $widget->getId(),
            'subjectType' => 'user',
            'subjectId' => (int) $reader->getId(),
            'permission' => 'read',
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/v1/widgets/'.$widget->getWidgetId());
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame('https://hooks.slack.com/services/T000/B000/secret', $this->json()['widget']['config']['slackWebhookUrl'], 'owner sees the real value');

        $this->authenticateClient($this->client, $reader);
        $this->client->request('GET', '/api/v1/widgets/'.$widget->getWidgetId());
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $config = $this->json()['widget']['config'];
        self::assertSame('***', $config['slackWebhookUrl']);
        self::assertSame('***', $config['externalApiToken']);
        self::assertSame('#123456', $config['primaryColor'], 'non-secret keys are untouched');
    }

    /**
     * @return list<int>
     */
    private function userIds(): array
    {
        $ids = [];
        foreach ($this->json()['subjects'] as $subject) {
            if ('user' === $subject['type']) {
                $ids[] = (int) $subject['id'];
            }
        }

        return $ids;
    }

    private function enableSharing(): void
    {
        $config = static::getContainer()->get(ConfigRepository::class);
        $config->setValue(0, IamConfig::CONFIG_GROUP, IamConfig::KEY_GROUPS_ENABLED, '1');
        $config->setValue(0, IamConfig::CONFIG_GROUP, IamConfig::KEY_SHARING_ENABLED, '1');
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
            ->setProviderId('hardening-test-'.uniqid())
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
        $group->setSlug(strtolower(str_replace(' ', '-', $name)).'-'.uniqid());
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

    private function createPrompt(int $ownerId, string $topic, string $name): Prompt
    {
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
