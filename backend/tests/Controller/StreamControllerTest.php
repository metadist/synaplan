<?php

namespace App\Tests\Controller;

use App\Entity\Chat;
use App\Entity\User;
use App\Service\TokenService;
use App\Tests\Trait\AuthenticatedTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class StreamControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;
    private ?string $token = null;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
    }

    private function getAuthToken(): string
    {
        if ($this->token) {
            return $this->token;
        }

        // Find test user
        $userRepository = static::getContainer()->get('doctrine')->getRepository(User::class);
        $user = $userRepository->findOneBy(['mail' => 'admin@synaplan.com']);

        if (!$user) {
            $this->fail('Test user not found');
        }

        // Generate access token using TokenService
        $this->token = $this->authenticateClient($this->client, $user);

        return $this->token;
    }

    private function createTestChat(): Chat
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $userRepository = $em->getRepository(User::class);
        $user = $userRepository->findOneBy(['mail' => 'admin@synaplan.com']);

        $chat = new Chat();
        $chat->setUserId($user->getId());
        $chat->setTitle('Stream Test Chat');

        $em->persist($chat);
        $em->flush();

        return $chat;
    }

    public function testStreamRequiresAuthentication(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $client->request('GET', '/api/v1/messages/stream', [
            'message' => 'Test',
            'chatId' => 1,
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testStreamRequiresMessage(): void
    {
        $this->markTestSkipped('SSE streaming tests are not compatible with PHPUnit WebTestCase (output buffering issues)');
    }

    public function testStreamRequiresChatId(): void
    {
        $this->markTestSkipped('SSE streaming tests are not compatible with PHPUnit WebTestCase (output buffering issues)');
    }

    public function testStreamSetsCorrectHeaders(): void
    {
        $this->markTestSkipped('SSE streaming tests are not compatible with PHPUnit WebTestCase (output buffering issues)');
    }

    public function testStreamAcceptsOptionalParameters(): void
    {
        $this->markTestSkipped('SSE streaming tests are not compatible with PHPUnit WebTestCase (output buffering issues)');
    }

    public function testStreamOnlyAcceptsGetMethod(): void
    {
        $token = $this->getAuthToken();

        $this->client->request('POST', '/api/v1/messages/stream', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);

        $this->assertResponseStatusCodeSame(405);
    }

    public function testStreamRejectsUnauthorizedChatAccess(): void
    {
        $this->markTestSkipped('SSE streaming tests are not compatible with PHPUnit WebTestCase (output buffering issues)');
    }
}
