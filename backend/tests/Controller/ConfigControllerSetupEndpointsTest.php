<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Token;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Access gates of the endpoints the provider-setup UI polls.
 *
 * `/config/features` reports infrastructure internals (service URLs, which env
 * vars are set, DB/Redis health) and is reachable in production, so the admin
 * gate is the only thing keeping it from being an information leak. It used to be
 * gated on `APP_ENV=dev` and is therefore easy to weaken by accident again.
 */
class ConfigControllerSetupEndpointsTest extends WebTestCase
{
    /** @var list<int> */
    private array $createdUserIds = [];

    protected function tearDown(): void
    {
        if ([] !== $this->createdUserIds) {
            self::ensureKernelShutdown();
            $client = static::createClient();
            $em = $client->getContainer()->get('doctrine')->getManager();
            foreach ($this->createdUserIds as $id) {
                foreach ($em->getRepository(Token::class)->findBy(['user' => $id]) as $token) {
                    $em->remove($token);
                }
            }
            $em->flush();
            foreach ($this->createdUserIds as $id) {
                $entity = $em->getRepository(User::class)->find($id);
                if ($entity) {
                    $em->remove($entity);
                }
            }
            $em->flush();
            $this->createdUserIds = [];
        }

        parent::tearDown();
    }

    private function loginAs(KernelBrowser $client, string $email, string $level): void
    {
        $em = $client->getContainer()->get('doctrine')->getManager();
        $password = 'SetupPass123!';

        $user = new User();
        $user->setMail($email);
        $user->setPw(password_hash($password, PASSWORD_BCRYPT));
        $user->setUserLevel($level);
        $user->setProviderId('local');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $user->setUserDetails([]);
        $em->persist($user);
        $em->flush();
        $this->createdUserIds[] = (int) $user->getId();

        $client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR),
        );
        $this->assertResponseIsSuccessful('Login should succeed for '.$email);
    }

    public function testFeaturesStatusRejectsAnonymousRequests(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();

        $client->request('GET', '/api/v1/config/features');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testFeaturesStatusRejectsNonAdminUsers(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginAs($client, 'features-non-admin@example.com', 'PRO');

        $client->request('GET', '/api/v1/config/features');

        $this->assertResponseStatusCodeSame(403);
        $payload = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Admin access required', $payload['error'] ?? null);
    }

    public function testLocalAiDownloadStatusRejectsAnonymousRequests(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();

        $client->request('GET', '/api/v1/config/local-ai/status');

        $this->assertResponseStatusCodeSame(401);
    }

    /**
     * The setup card polls this every few seconds, so the payload shape matters:
     * a missing status file must still answer with a well-formed "idle".
     */
    public function testLocalAiDownloadStatusAnswersAuthenticatedUsersWithAKnownShape(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $this->loginAs($client, 'local-ai-status@example.com', 'PRO');

        $client->request('GET', '/api/v1/config/local-ai/status');

        $this->assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($payload);
        foreach (['status', 'currentModel', 'percent', 'message', 'models', 'updatedAt'] as $key) {
            $this->assertArrayHasKey($key, $payload);
        }
        $this->assertContains($payload['status'], ['idle', 'waiting', 'downloading', 'ready', 'error']);
        $this->assertIsArray($payload['models']);
    }
}
