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
            ->willReturn(['success' => true]);

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
            ->willReturn(['success' => true]);

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
}
