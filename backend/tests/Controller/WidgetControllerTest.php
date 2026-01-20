<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Prompt;
use App\Entity\User;
use App\Entity\Widget;
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
                'topic' => 'widget-default',
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
        $this->assertEquals('widget-default', $responseData['widget']['taskPromptTopic']);
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
}
