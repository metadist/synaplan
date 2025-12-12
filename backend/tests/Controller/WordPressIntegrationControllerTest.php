<?php

namespace App\Tests\Controller;

use App\Service\WordPressIntegrationService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class WordPressIntegrationControllerTest extends WebTestCase
{
    public function testStep1EndpointCallsService(): void
    {
        $client = static::createClient();

        $service = $this->createMock(WordPressIntegrationService::class);
        $service->expects($this->once())
            ->method('step1VerifyAndCreateUser')
            ->with($this->arrayHasKey('verification_token'))
            ->willReturn(['success' => true, 'data' => ['user_id' => 1]]);

        static::getContainer()->set(WordPressIntegrationService::class, $service);

        $client->request(
            'POST',
            '/api/v1/integrations/wordpress/step1',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['verification_token' => 'abc', 'verification_url' => 'https://example.com'])
        );

        self::assertResponseIsSuccessful();
    }

    public function testLegacyStep1ActionDelegatesToService(): void
    {
        $client = static::createClient();

        $service = $this->createMock(WordPressIntegrationService::class);
        $service->expects($this->once())
            ->method('step1VerifyAndCreateUser')
            ->willReturn(['success' => true, 'data' => ['user_id' => 1]]);

        static::getContainer()->set(WordPressIntegrationService::class, $service);

        $client->request(
            'POST',
            '/api.php?action=wpStep1VerifyAndCreateUser',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['verification_token' => 'abc', 'verification_url' => 'https://example.com'])
        );

        self::assertResponseIsSuccessful();
    }

    public function testStep2EndpointCreatesApiKey(): void
    {
        $client = static::createClient();

        $service = $this->createMock(WordPressIntegrationService::class);
        $service->expects($this->once())
            ->method('step2CreateApiKey')
            ->with(123)
            ->willReturn(['success' => true, 'data' => ['api_key' => 'sk_test_123']]);

        static::getContainer()->set(WordPressIntegrationService::class, $service);

        $client->request(
            'POST',
            '/api/v1/integrations/wordpress/step2',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['user_id' => 123])
        );

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('data', $data);
        self::assertArrayHasKey('api_key', $data['data']);
    }

    public function testLegacyStep2ActionCreatesApiKey(): void
    {
        $client = static::createClient();

        $service = $this->createMock(WordPressIntegrationService::class);
        $service->expects($this->once())
            ->method('step2CreateApiKey')
            ->with(123)
            ->willReturn(['success' => true, 'data' => ['api_key' => 'sk_test_123']]);

        static::getContainer()->set(WordPressIntegrationService::class, $service);

        $client->request(
            'POST',
            '/api.php?action=wpStep2CreateApiKey',
            ['user_id' => 123]
        );

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertTrue($data['success']);
    }

    public function testStep5EndpointSavesWidgetWithCamelCaseParams(): void
    {
        $client = static::createClient();

        $service = $this->createMock(WordPressIntegrationService::class);
        $service->expects($this->once())
            ->method('step5SaveWidget')
            ->with(
                123,
                $this->callback(function ($payload) {
                    // Verify that camelCase parameters are present
                    return isset($payload['widgetColor'])
                        && isset($payload['widgetPosition'])
                        && isset($payload['autoMessage']);
                })
            )
            ->willReturn([
                'success' => true,
                'data' => [
                    'widget_id' => 'widget_abc123',
                    'widget_configured' => true,
                ],
            ]);

        static::getContainer()->set(WordPressIntegrationService::class, $service);

        // WordPress plugin sends camelCase parameters
        $client->request(
            'POST',
            '/api/v1/integrations/wordpress/step5',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'user_id' => 123,
                'widgetColor' => '#007bff',
                'widgetIconColor' => '#ffffff',
                'widgetPosition' => 'bottom-right',
                'autoMessage' => 'Hello! How can I help you today?',
                'widgetPrompt' => 'general',
                'autoOpen' => '0',
                'integrationType' => 'floating-button',
            ])
        );

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('widget_id', $data['data']);
    }

    public function testLegacyStep5ActionSavesWidgetWithCamelCaseParams(): void
    {
        $client = static::createClient();

        $service = $this->createMock(WordPressIntegrationService::class);
        $service->expects($this->once())
            ->method('step5SaveWidget')
            ->willReturn([
                'success' => true,
                'data' => [
                    'widget_id' => 'widget_abc123',
                    'widget_configured' => true,
                ],
            ]);

        static::getContainer()->set(WordPressIntegrationService::class, $service);

        $client->request(
            'POST',
            '/api.php?action=wpStep5SaveWidget',
            [
                'user_id' => 123,
                'widgetColor' => '#007bff',
                'widgetPosition' => 'bottom-right',
                'autoMessage' => 'Hello!',
                'widgetPrompt' => 'general',
            ]
        );

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertTrue($data['success']);
    }

    public function testStep5EndpointSavesWidgetWithSnakeCaseParams(): void
    {
        $client = static::createClient();

        $service = $this->createMock(WordPressIntegrationService::class);
        $service->expects($this->once())
            ->method('step5SaveWidget')
            ->with(
                123,
                $this->callback(function ($payload) {
                    // Verify that snake_case parameters are present
                    return isset($payload['primary_color'])
                        && isset($payload['position'])
                        && isset($payload['auto_message']);
                })
            )
            ->willReturn([
                'success' => true,
                'data' => [
                    'widget_id' => 'widget_abc123',
                    'widget_configured' => true,
                ],
            ]);

        static::getContainer()->set(WordPressIntegrationService::class, $service);

        // New API uses snake_case parameters
        $client->request(
            'POST',
            '/api/v1/integrations/wordpress/step5',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'user_id' => 123,
                'primary_color' => '#007bff',
                'icon_color' => '#ffffff',
                'position' => 'bottom-right',
                'auto_message' => 'Hello! How can I help you today?',
                'task_prompt_topic' => 'general',
                'auto_open' => false,
                'integration_type' => 'floating-button',
                'site_url' => 'https://example.com',
            ])
        );

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertTrue($data['success']);
    }

    public function testCompleteWizardEndpoint(): void
    {
        $client = static::createClient();

        $service = $this->createMock(WordPressIntegrationService::class);
        $service->expects($this->once())
            ->method('completeWizard')
            ->willReturn([
                'success' => true,
                'message' => 'WordPress wizard completed successfully',
                'data' => [
                    'user_id' => 123,
                    'api_key' => 'sk_test_123',
                    'filesProcessed' => 0,
                    'widget_id' => 'widget_abc123',
                ],
            ]);

        static::getContainer()->set(WordPressIntegrationService::class, $service);

        $client->request(
            'POST',
            '/api/v1/integrations/wordpress/complete',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'test@example.com',
                'password' => 'Test123!',
                'verification_token' => 'abc',
                'verification_url' => 'https://example.com/verify',
                'site_url' => 'https://example.com',
            ])
        );

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('data', $data);
    }

    public function testLegacyCompleteWizardAction(): void
    {
        $client = static::createClient();

        $service = $this->createMock(WordPressIntegrationService::class);
        $service->expects($this->once())
            ->method('completeWizard')
            ->willReturn([
                'success' => true,
                'message' => 'WordPress wizard completed successfully',
                'data' => [
                    'user_id' => 123,
                    'api_key' => 'sk_test_123',
                    'filesProcessed' => 0,
                    'widget_id' => 'widget_abc123',
                ],
            ]);

        static::getContainer()->set(WordPressIntegrationService::class, $service);

        $client->request(
            'POST',
            '/api.php?action=wpWizardComplete',
            [
                'email' => 'test@example.com',
                'password' => 'Test123!',
                'verification_token' => 'abc',
                'verification_url' => 'https://example.com/verify',
                'site_url' => 'https://example.com',
            ]
        );

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertTrue($data['success']);
    }
}
