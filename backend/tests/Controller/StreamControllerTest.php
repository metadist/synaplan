<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class StreamControllerTest extends WebTestCase
{
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

    public function testStreamOnlyAcceptsGetMethod(): void
    {
        // Method Not Allowed is a routing error — checked before auth.
        $this->client->request('POST', '/api/v1/messages/stream');

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
