<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Tests\Trait\AuthenticatedTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class StreamControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
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

    public function testStreamAcceptsPostMethod(): void
    {
        // POST is the default transport for the web chat (the message rides
        // in the JSON body, so long texts never exceed URL length limits).
        // Unauthenticated POST must reach the controller (401, not 405).
        $this->client->request(
            'POST',
            '/api/v1/messages/stream',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['message' => 'Test', 'chatId' => '1'])
        );

        $this->assertResponseStatusCodeSame(401);
    }

    public function testCookieSessionCannotStartATurnThroughGet(): void
    {
        // What an <img src> in somebody else's text sends: the URL and the
        // reader's cookie, nothing else.
        $this->authenticateClient($this->client, $this->demoUser());

        $this->client->request('GET', '/api/v1/messages/stream', [
            'message' => 'forged-by-image',
            'chatId' => 1,
        ]);

        $this->assertResponseStatusCodeSame(405);
        $this->assertSame('POST', $this->client->getResponse()->headers->get('Allow'));
    }

    public function testGuestSessionCannotStartATurnThroughGet(): void
    {
        $this->client->request('GET', '/api/v1/messages/stream', [
            'message' => 'forged-by-image',
            'guestSession' => '6f1c1f0e-3b0a-4c58-9d55-0c1c7a9e2a11',
        ]);

        $this->assertResponseStatusCodeSame(405);
    }

    public function testGetWithABearerHeaderStillReachesTheResolver(): void
    {
        // API clients keep the GET transport. Without a message the request
        // ends in the resolver's own answer, which proves it got that far.
        $token = $this->authenticateClient($this->client, $this->demoUser());
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', '/api/v1/messages/stream', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);

        $this->assertNotSame(405, $this->client->getResponse()->getStatusCode());
        $this->assertNotSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testGetWithAQueryTokenStillReachesTheResolver(): void
    {
        $token = $this->authenticateClient($this->client, $this->demoUser());
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', '/api/v1/messages/stream', ['token' => $token]);

        $this->assertNotSame(405, $this->client->getResponse()->getStatusCode());
        $this->assertNotSame(401, $this->client->getResponse()->getStatusCode());
    }

    private function demoUser(): User
    {
        $user = $this->client->getContainer()->get('doctrine')->getRepository(User::class)
            ->findOneBy(['mail' => 'demo@synaplan.com']);
        if (!$user instanceof User) {
            self::markTestSkipped('Fixture user demo@synaplan.com is required.');
        }

        return $user;
    }

    public function testStreamRejectsOtherMethods(): void
    {
        // Method Not Allowed is a routing error — checked before auth.
        $this->client->request('DELETE', '/api/v1/messages/stream');

        $this->assertResponseStatusCodeSame(405);
    }

    public function testDisableMemoriesParameterIsAccepted(): void
    {
        // The endpoint must not reject a GET with disableMemories=1 before auth (it returns 401, not 400/422).
        $this->client->request('GET', '/api/v1/messages/stream', [
            'message' => 'Hello',
            'chatId' => 1,
            'disableMemories' => '1',
        ]);

        // Without auth we get 401, but the query parameter itself must not cause a 400/422.
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [401, 405], 'disableMemories=1 must not produce a 4xx validation error');
    }

    public function testStreamRejectsUnauthorizedChatAccess(): void
    {
        $this->markTestSkipped('SSE streaming tests are not compatible with PHPUnit WebTestCase (output buffering issues)');
    }
}
