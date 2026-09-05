<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Prompt;
use App\Entity\User;
use App\Entity\Widget;
use App\Repository\ConfigRepository;
use App\Service\Iam\IamConfig;
use App\Tests\Trait\AuthenticatedTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration tests for WidgetController.
 */
class WidgetControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private $client;
    private $em;
    private ?User $testUser = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get('doctrine')->getManager();
        $this->testUser = $this->createTestUser();
        $this->ensureDefaultPromptExists();
    }

    private function ensureDefaultPromptExists(): void
    {
        $promptsToCreate = [
            [
                'topic' => 'tools:widget-default',
                'description' => 'Default widget prompt for tests',
                'prompt' => 'You are a helpful assistant. Answer questions clearly and concisely.',
            ],
            [
                'topic' => 'general',
                'description' => 'General purpose prompt for tests',
                'prompt' => 'You are a general purpose assistant.',
            ],
        ];

        foreach ($promptsToCreate as $promptData) {
            $existingPrompt = $this->em->getRepository(Prompt::class)
                ->findOneBy(['topic' => $promptData['topic'], 'ownerId' => 0]);

            if (!$existingPrompt) {
                $prompt = new Prompt();
                $prompt->setOwnerId(0);
                $prompt->setLanguage('en');
                $prompt->setTopic($promptData['topic']);
                $prompt->setShortDescription($promptData['description']);
                $prompt->setPrompt($promptData['prompt']);
                $this->em->persist($prompt);
            }
        }
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        // Cleanup test widgets and user
        if ($this->testUser && $this->em->isOpen()) {
            $userId = $this->testUser->getId();

            // Use DQL to delete widgets (avoids detached entity issues)
            $this->em->createQuery('DELETE FROM App\Entity\Widget w WHERE w.ownerId = :ownerId')
                ->setParameter('ownerId', $userId)
                ->execute();

            // Use DQL to delete user
            $this->em->createQuery('DELETE FROM App\Entity\User u WHERE u.id = :id')
                ->setParameter('id', $userId)
                ->execute();
        }

        $this->testUser = null;
        parent::tearDown();
    }

    private function createTestUser(): User
    {
        $user = new User();
        $user->setMail('widgettest'.time().'@test.com');
        $user->setPw(password_hash('password', PASSWORD_BCRYPT));
        $user->setUserLevel('NEW');
        $user->setProviderId('local');
        $user->setCreated(date('YmdHis'));
        $user->setType('WEB');
        $user->setEmailVerified(true);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function authenticate(): void
    {
        $this->authenticateClient($this->client, $this->testUser);
    }

    public function testCreateWidgetRequiresAuthentication(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/widgets',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'Test Widget',
            ])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testCreateWidgetRequiresName(): void
    {
        $this->authenticate();

        $this->client->request(
            'POST',
            '/api/v1/widgets',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('name', $responseData['error']);
    }

    public function testCreateWidgetWithOnlyName(): void
    {
        $this->authenticate();

        $this->client->request(
            'POST',
            '/api/v1/widgets',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'Test Widget',
            ])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($responseData['success']);
        $this->assertArrayHasKey('widget', $responseData);
        $this->assertEquals('Test Widget', $responseData['widget']['name']);
        // Should use default prompt when not specified
        $this->assertEquals('tools:widget-default', $responseData['widget']['taskPromptTopic']);
    }

    public function testCreateWidgetWithWebsiteUrl(): void
    {
        $this->authenticate();

        $this->client->request(
            'POST',
            '/api/v1/widgets',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'Support Widget',
                'websiteUrl' => 'https://example.com',
            ])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($responseData['success']);
        $this->assertArrayHasKey('widget', $responseData);
        $this->assertEquals('Support Widget', $responseData['widget']['name']);
        // Website domain should be in allowed domains
        $this->assertContains('example.com', $responseData['widget']['allowedDomains']);
    }

    public function testCreateWidgetWithCustomTaskPrompt(): void
    {
        $this->authenticate();

        // Create widget with a custom task prompt (assuming 'general' exists)
        $this->client->request(
            'POST',
            '/api/v1/widgets',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'Custom Widget',
                'taskPromptTopic' => 'general',
            ])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($responseData['success']);
        $this->assertEquals('general', $responseData['widget']['taskPromptTopic']);
    }

    /**
     * Standard sorting: the "__standard__" sentinel must create a widget with an
     * empty task prompt (no pinned prompt) so it runs the owner's normal routing.
     * No prompt-existence validation should be applied.
     */
    public function testCreateWidgetWithStandardSorting(): void
    {
        $this->authenticate();

        $this->client->request(
            'POST',
            '/api/v1/widgets',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'Standard Routing Widget',
                'taskPromptTopic' => '__standard__',
            ])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($responseData['success']);
        $this->assertSame('', $responseData['widget']['taskPromptTopic']);
    }

    public function testListWidgetsRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/widgets');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testListWidgets(): void
    {
        $this->authenticate();

        // First create a widget
        $this->client->request(
            'POST',
            '/api/v1/widgets',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'List Test Widget',
            ])
        );

        // Then list widgets
        $this->client->request('GET', '/api/v1/widgets');

        $this->assertResponseIsSuccessful();

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($responseData['success']);
        $this->assertIsArray($responseData['widgets']);
    }

    public function testDeleteWidgetRequiresAuthentication(): void
    {
        $this->client->request('DELETE', '/api/v1/widgets/wdg_nonexistent');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testDeleteNonexistentWidget(): void
    {
        $this->authenticate();

        $this->client->request('DELETE', '/api/v1/widgets/wdg_nonexistent');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testSetupChatRequiresAuthentication(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/widgets/wdg_test/setup-chat',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['text' => 'Hello'])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testGeneratePromptRequiresAuthentication(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/widgets/wdg_test/generate-prompt',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['generatedPrompt' => 'Test prompt'])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testGeneratePromptRequiresPromptField(): void
    {
        $this->authenticate();

        // First create a widget
        $this->client->request(
            'POST',
            '/api/v1/widgets',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Prompt Test Widget'])
        );

        $createResponse = json_decode($this->client->getResponse()->getContent(), true);
        $widgetId = $createResponse['widget']['widgetId'];

        // Try to generate prompt without the required field
        $this->client->request(
            'POST',
            "/api/v1/widgets/{$widgetId}/generate-prompt",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('generatedPrompt', $responseData['error']);
    }

    public function testReadShareLetsMemberGetWidgetButNotEmbedOrSessions(): void
    {
        $this->enableSharing();
        $member = $this->createNamedUser('widget-share-member@synaplan.internal');
        $this->authenticate();
        $created = $this->createWidgetNamed('Share Read Widget');
        $this->postJson('/api/v1/shares', [
            'kind' => 'widget',
            'resource' => (string) $created['id'],
            'subjectType' => 'user',
            'subjectId' => (int) $member->getId(),
            'permission' => 'read',
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());

        $this->authenticateClient($this->client, $member);
        $this->client->request('GET', '/api/v1/widgets/'.$created['widgetId']);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($body['widget']['shared']);
        self::assertSame('read', $body['widget']['access']);

        $this->client->request(
            'PUT',
            '/api/v1/widgets/'.$created['widgetId'],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Hijacked'], JSON_THROW_ON_ERROR)
        );
        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/v1/widgets/'.$created['widgetId'].'/embed');
        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/v1/widgets/'.$created['widgetId'].'/sessions');
        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    public function testEditShareLetsMemberUpdateWidget(): void
    {
        $this->enableSharing();
        $member = $this->createNamedUser('widget-edit-member@synaplan.internal');
        $this->authenticate();
        $created = $this->createWidgetNamed('Share Edit Widget');
        $this->postJson('/api/v1/shares', [
            'kind' => 'widget',
            'resource' => (string) $created['id'],
            'subjectType' => 'user',
            'subjectId' => (int) $member->getId(),
            'permission' => 'edit',
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());

        $this->authenticateClient($this->client, $member);
        $this->client->request(
            'PUT',
            '/api/v1/widgets/'.$created['widgetId'],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Co-edited'], JSON_THROW_ON_ERROR)
        );
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    public function testAdminCannotReadForeignWidgetSessions(): void
    {
        $this->enableSharing();
        $admin = $this->createNamedUser('widget-c8-admin@synaplan.internal');
        $admin->setUserLevel('ADMIN');
        $this->em->flush();
        $this->authenticate();
        $created = $this->createWidgetNamed('C8 Widget');

        $this->authenticateClient($this->client, $admin);
        $this->client->request('GET', '/api/v1/widgets/'.$created['widgetId'].'/sessions');

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    private function enableSharing(): void
    {
        $config = static::getContainer()->get(ConfigRepository::class);
        $config->setValue(0, IamConfig::CONFIG_GROUP, IamConfig::KEY_GROUPS_ENABLED, '1');
        $config->setValue(0, IamConfig::CONFIG_GROUP, IamConfig::KEY_SHARING_ENABLED, '1');
        $this->em->flush();
    }

    private function createNamedUser(string $email): User
    {
        $existing = $this->em->getRepository(User::class)->findOneBy(['mail' => $email]);
        if ($existing instanceof User) {
            return $existing;
        }

        $user = (new User())
            ->setMail($email)
            ->setType('WEB')
            ->setProviderId('widget-share-'.uniqid())
            ->setUserLevel('NEW');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @return array{id: int, widgetId: string}
     */
    private function createWidgetNamed(string $name): array
    {
        $this->client->request(
            'POST',
            '/api/v1/widgets',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => $name], JSON_THROW_ON_ERROR)
        );
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertIsInt($body['widget']['id']);
        self::assertIsString($body['widget']['widgetId']);

        return ['id' => $body['widget']['id'], 'widgetId' => $body['widget']['widgetId']];
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
}
