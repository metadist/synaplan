<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Service\Widget\WordPressIntegrationService;
use App\Tests\Trait\AuthenticatedTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class WordPressIntegrationControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    public function testAnonymousWizardStepsAreRejected(): void
    {
        $client = static::createClient();

        foreach ([
            ['POST', '/api/v1/integrations/wordpress/step2', ['user_id' => 1]],
            ['POST', '/api/v1/integrations/wordpress/step1', ['email' => 'admin@synaplan.com']],
            ['POST', '/api/v1/integrations/wordpress/complete', ['email' => 'admin@synaplan.com', 'user_id' => 1]],
            ['POST', '/api.php?action=wpStep2CreateApiKey', ['user_id' => 1]],
            ['GET', '/api.php?action=getProfile&user_id=1', []],
            ['GET', '/api.php?action=createApiKey&user_id=1', []],
        ] as [$method, $uri, $payload]) {
            $client->request(
                $method,
                $uri,
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                [] === $payload ? null : (string) json_encode($payload)
            );
            self::assertResponseStatusCodeSame(401, $uri);
        }
    }

    public function testStep2UsesTheSignedInAccountAndIgnoresUserId(): void
    {
        [$client, $user, $token] = $this->signedIn();

        $service = $this->createMock(WordPressIntegrationService::class);
        $service->expects($this->once())
            ->method('step2CreateApiKey')
            ->with((int) $user->getId())
            ->willReturn(['success' => true, 'data' => ['api_key' => 'sk_test_123']]);
        static::getContainer()->set(WordPressIntegrationService::class, $service);

        $client->request(
            'POST',
            '/api/v1/integrations/wordpress/step2',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token],
            (string) json_encode(['user_id' => 123])
        );

        self::assertResponseIsSuccessful();
    }

    public function testLegacyStep2UsesTheSignedInAccount(): void
    {
        [$client, $user, $token] = $this->signedIn();

        $service = $this->createMock(WordPressIntegrationService::class);
        $service->expects($this->once())
            ->method('step2CreateApiKey')
            ->with((int) $user->getId())
            ->willReturn(['success' => true, 'data' => ['api_key' => 'sk_test_123']]);
        static::getContainer()->set(WordPressIntegrationService::class, $service);

        $client->request(
            'POST',
            '/api.php?action=wpStep2CreateApiKey',
            ['user_id' => 123],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
        );

        self::assertResponseIsSuccessful();
    }

    public function testStep1RefusesAnotherAccount(): void
    {
        [$client, , $token] = $this->signedIn();

        $service = $this->createMock(WordPressIntegrationService::class);
        $service->expects($this->never())->method('step1VerifyAndCreateUser');
        static::getContainer()->set(WordPressIntegrationService::class, $service);

        $client->request(
            'POST',
            '/api/v1/integrations/wordpress/step1',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token],
            (string) json_encode([
                'email' => 'someone-else@example.com',
                'password' => 'secret',
                'verification_token' => 'abc',
                'verification_url' => 'https://example.com/verify',
            ])
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testStep1AllowsTheSignedInEmail(): void
    {
        [$client, $user, $token] = $this->signedIn();

        $service = $this->createMock(WordPressIntegrationService::class);
        $service->expects($this->once())
            ->method('step1VerifyAndCreateUser')
            ->with($this->callback(static function (array $payload) use ($user): bool {
                return ($payload['email'] ?? null) === $user->getMail();
            }))
            ->willReturn(['success' => true, 'data' => ['user_id' => (int) $user->getId()]]);
        static::getContainer()->set(WordPressIntegrationService::class, $service);

        $client->request(
            'POST',
            '/api/v1/integrations/wordpress/step1',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token],
            (string) json_encode([
                'email' => $user->getMail(),
                'verification_token' => 'abc',
                'verification_url' => 'https://example.com/verify',
            ])
        );

        self::assertResponseIsSuccessful();
    }

    public function testStep5KeepsWidgetFieldsButUsesTheSignedInAccount(): void
    {
        [$client, $user, $token] = $this->signedIn();

        $service = $this->createMock(WordPressIntegrationService::class);
        $service->expects($this->once())
            ->method('step5SaveWidget')
            ->with(
                (int) $user->getId(),
                $this->callback(static function (array $payload): bool {
                    return isset($payload['widgetColor'], $payload['widgetPosition'], $payload['autoMessage'])
                        && 123 === (int) ($payload['user_id'] ?? 0);
                })
            )
            ->willReturn(['success' => true, 'data' => ['widget_id' => '1', 'widget_configured' => true]]);
        static::getContainer()->set(WordPressIntegrationService::class, $service);

        $client->request(
            'POST',
            '/api/v1/integrations/wordpress/step5',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token],
            (string) json_encode([
                'user_id' => 123,
                'widgetColor' => '#007bff',
                'widgetPosition' => 'bottom-right',
                'autoMessage' => 'Hello',
            ])
        );

        self::assertResponseIsSuccessful();
    }

    public function testLegacyStep5UsesTheSignedInAccount(): void
    {
        [$client, $user, $token] = $this->signedIn();

        $service = $this->createMock(WordPressIntegrationService::class);
        $service->expects($this->once())
            ->method('step5SaveWidget')
            ->with((int) $user->getId(), $this->anything())
            ->willReturn(['success' => true, 'data' => ['widget_id' => '1', 'widget_configured' => true]]);
        static::getContainer()->set(WordPressIntegrationService::class, $service);

        $client->request(
            'POST',
            '/api.php?action=wpStep5SaveWidget',
            [
                'user_id' => 123,
                'widgetColor' => '#007bff',
                'widgetPosition' => 'bottom-right',
                'autoMessage' => 'Hello',
            ],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
        );

        self::assertResponseIsSuccessful();
    }

    public function testStep5AcceptsSnakeCaseFields(): void
    {
        [$client, $user, $token] = $this->signedIn();

        $service = $this->createMock(WordPressIntegrationService::class);
        $service->expects($this->once())
            ->method('step5SaveWidget')
            ->with(
                (int) $user->getId(),
                $this->callback(static function (array $payload): bool {
                    return isset($payload['primary_color'], $payload['position'], $payload['auto_message']);
                })
            )
            ->willReturn(['success' => true, 'data' => ['widget_id' => '1', 'widget_configured' => true]]);
        static::getContainer()->set(WordPressIntegrationService::class, $service);

        $client->request(
            'POST',
            '/api/v1/integrations/wordpress/step5',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token],
            (string) json_encode([
                'primary_color' => '#007bff',
                'position' => 'bottom-right',
                'auto_message' => 'Hello',
            ])
        );

        self::assertResponseIsSuccessful();
    }

    public function testCompleteRefusesAnotherAccount(): void
    {
        [$client, , $token] = $this->signedIn();

        $service = $this->createMock(WordPressIntegrationService::class);
        $service->expects($this->never())->method('completeWizard');
        static::getContainer()->set(WordPressIntegrationService::class, $service);

        $client->request(
            'POST',
            '/api/v1/integrations/wordpress/complete',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token],
            (string) json_encode([
                'email' => 'someone-else@example.com',
                'password' => 'secret',
                'verification_token' => 'abc',
                'verification_url' => 'https://example.com/verify',
                'site_url' => 'https://example.com',
            ])
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testCompleteAllowsTheSignedInEmail(): void
    {
        [$client, $user, $token] = $this->signedIn();

        $service = $this->createMock(WordPressIntegrationService::class);
        $service->expects($this->once())
            ->method('completeWizard')
            ->willReturn([
                'success' => true,
                'data' => ['user_id' => (int) $user->getId(), 'api_key' => 'sk_test_123'],
            ]);
        static::getContainer()->set(WordPressIntegrationService::class, $service);

        $client->request(
            'POST',
            '/api/v1/integrations/wordpress/complete',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token],
            (string) json_encode([
                'email' => $user->getMail(),
                'password' => 'secret',
                'verification_token' => 'abc',
                'verification_url' => 'https://example.com/verify',
                'site_url' => 'https://example.com',
            ])
        );

        self::assertResponseIsSuccessful();
    }

    public function testLegacyCompleteRefusesAnotherAccount(): void
    {
        [$client, , $token] = $this->signedIn();

        $service = $this->createMock(WordPressIntegrationService::class);
        $service->expects($this->never())->method('completeWizard');
        static::getContainer()->set(WordPressIntegrationService::class, $service);

        $client->request(
            'POST',
            '/api.php?action=wpWizardComplete',
            [
                'email' => 'someone-else@example.com',
                'password' => 'secret',
                'verification_token' => 'abc',
                'verification_url' => 'https://example.com/verify',
                'site_url' => 'https://example.com',
            ],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer '.$token]
        );

        self::assertResponseStatusCodeSame(400);
    }

    /**
     * @return array{0: KernelBrowser, 1: User, 2: string}
     */
    private function signedIn(): array
    {
        $client = static::createClient();
        $user = $client->getContainer()->get('doctrine')->getRepository(User::class)->findOneBy(['mail' => 'admin@synaplan.com']);
        if (!$user instanceof User) {
            self::markTestSkipped('Fixture user admin@synaplan.com not found.');
        }

        return [$client, $user, $this->authenticateClient($client, $user)];
    }
}
