<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ApiKey;
use App\Entity\Chat;
use App\Entity\File;
use App\Entity\Message;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class McpControllerTest extends WebTestCase
{
    private const API_KEY = 'sk_test_mcp_endpoint_key_1234567890abcdef';
    private const PROTOCOL_VERSION = '2025-11-25';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();

        $this->createUserWithApiKey();
    }

    public function testRejectsUnauthenticatedRequests(): void
    {
        $this->client->request(
            'POST',
            '/mcp',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode($this->initializePayload()),
        );

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertStringContainsString(
            'resource_metadata=',
            (string) $response->headers->get('WWW-Authenticate'),
        );
    }

    public function testUnknownSessionReturnsJsonRpcErrorNot500(): void
    {
        // Issue #1110 — a syntactically invalid / never-initialized session id
        // must yield a clean JSON-RPC -32600 error, not a raw 500.
        $result = $this->jsonRpc(
            [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => new \stdClass(),
            ],
            'fake-session-id-that-does-not-exist',
        );

        self::assertLessThan(500, $this->client->getResponse()->getStatusCode(), json_encode($result));
        self::assertArrayHasKey('error', $result, json_encode($result));
        self::assertSame(-32600, $result['error']['code']);
    }

    public function testProtectedResourceMetadataIsPublic(): void
    {
        $this->client->request('GET', '/.well-known/oauth-protected-resource/mcp');

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $doc = json_decode((string) $response->getContent(), true);
        self::assertIsArray($doc);
        self::assertArrayHasKey('resource', $doc);
        self::assertStringEndsWith('/mcp', (string) $doc['resource']);
        self::assertContains('header', $doc['bearer_methods_supported']);
    }

    public function testOptionsPreflightSucceedsWithoutAuth(): void
    {
        $this->client->request('OPTIONS', '/mcp');

        self::assertSame(Response::HTTP_NO_CONTENT, $this->client->getResponse()->getStatusCode());
    }

    public function testInitializeReturnsServerInfoAndSession(): void
    {
        $sessionId = $this->initialize();

        self::assertNotSame('', $sessionId, 'initialize must return an Mcp-Session-Id header');
    }

    public function testToolsListExposesCuratedToolCatalog(): void
    {
        // initialize → tools/list must share the MCP session, so keep one kernel.
        $this->client->disableReboot();

        $sessionId = $this->initialize();

        $result = $this->jsonRpc(
            [
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/list',
                'params' => new \stdClass(),
            ],
            $sessionId,
        );

        self::assertArrayHasKey('result', $result, json_encode($result));
        self::assertArrayHasKey('tools', $result['result']);

        $toolNames = array_map(static fn (array $t): string => $t['name'], $result['result']['tools']);
        self::assertContains('synaplan_chat', $toolNames);
        self::assertContains('rag_search', $toolNames);
        self::assertContains('memory_search', $toolNames);
        self::assertContains('memory_add', $toolNames);
        self::assertContains('file_ingest', $toolNames);
        self::assertContains('rag_similar', $toolNames);
        self::assertContains('list_chats', $toolNames);
        self::assertContains('get_messages', $toolNames);
        self::assertContains('list_prompts', $toolNames);
    }

    public function testResourceTemplatesAreListed(): void
    {
        $this->client->disableReboot();

        $sessionId = $this->initialize();

        $result = $this->jsonRpc(
            [
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'resources/templates/list',
                'params' => new \stdClass(),
            ],
            $sessionId,
        );

        self::assertArrayHasKey('result', $result, json_encode($result));
        self::assertArrayHasKey('resourceTemplates', $result['result']);

        $uriTemplates = array_map(
            static fn (array $t): string => $t['uriTemplate'],
            $result['result']['resourceTemplates'],
        );
        self::assertContains('synaplan://file/{id}', $uriTemplates);
        self::assertContains('synaplan://memory/{id}', $uriTemplates);
    }

    public function testPromptsListExcludesInternalToolPrompts(): void
    {
        $this->client->disableReboot();

        $sessionId = $this->initialize();

        $result = $this->jsonRpc(
            [
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'prompts/list',
                'params' => new \stdClass(),
            ],
            $sessionId,
        );

        self::assertArrayHasKey('result', $result, json_encode($result));
        self::assertArrayHasKey('prompts', $result['result']);

        // Whatever task prompts are exposed, internal `tools:*` prompts must never leak.
        foreach ($result['result']['prompts'] as $prompt) {
            self::assertStringStartsNotWith('tools:', (string) $prompt['name']);
        }
    }

    public function testGetMessagesAndListChatsReflectSeededConversation(): void
    {
        $this->client->disableReboot();
        $ids = $this->seedConversationAndDocument();
        $sessionId = $this->initialize();

        $messages = $this->callTool($sessionId, 'get_messages', ['chat_id' => $ids['chatId']], 2);
        $sc = $messages['result']['structuredContent'] ?? null;
        self::assertIsArray($sc, json_encode($messages));
        self::assertTrue($sc['success']);
        self::assertCount(2, $sc['messages']);
        self::assertSame('user', $sc['messages'][0]['role']);
        self::assertSame('assistant', $sc['messages'][1]['role']);

        $chats = $this->callTool($sessionId, 'list_chats', ['limit' => 100], 3);
        $sc = $chats['result']['structuredContent'] ?? null;
        self::assertIsArray($sc, json_encode($chats));
        $match = array_values(array_filter(
            $sc['chats'],
            static fn (array $c): bool => $c['id'] === $ids['chatId'],
        ));
        self::assertNotEmpty($match, 'seeded chat must appear in list_chats');
        self::assertSame(2, $match[0]['message_count']);
    }

    public function testGetMessagesUnknownChatReportsToolError(): void
    {
        $this->client->disableReboot();
        $sessionId = $this->initialize();

        $result = $this->callTool($sessionId, 'get_messages', ['chat_id' => 999999999], 2);

        // Tool-level failures must be reported via the MCP isError flag.
        self::assertTrue($result['result']['isError'] ?? false, json_encode($result));
    }

    public function testResourceReadReturnsDocumentText(): void
    {
        $this->client->disableReboot();
        $ids = $this->seedConversationAndDocument();
        $sessionId = $this->initialize();

        $result = $this->jsonRpc(
            [
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'resources/read',
                'params' => ['uri' => 'synaplan://file/'.$ids['fileId']],
            ],
            $sessionId,
        );

        self::assertArrayHasKey('result', $result, json_encode($result));
        self::assertStringContainsString('PURPLEFOX', (string) $result['result']['contents'][0]['text']);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function callTool(string $sessionId, string $name, array $arguments, int $id): array
    {
        return $this->jsonRpc(
            [
                'jsonrpc' => '2.0',
                'id' => $id,
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => [] === $arguments ? new \stdClass() : $arguments],
            ],
            $sessionId,
        );
    }

    /**
     * Seed a chat (with one user + one assistant message) and a document for the
     * test user, all within the rolled-back test transaction.
     *
     * @return array{chatId: int, fileId: int}
     */
    private function seedConversationAndDocument(): array
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['mail' => 'mcp-test@synaplan.internal']);
        self::assertInstanceOf(User::class, $user);
        $userId = (int) $user->getId();
        $trackingId = time();

        $chat = (new Chat())
            ->setUserId($userId)
            ->setSource('mcp')
            ->setTitle('MCP Test Chat');
        $this->em->persist($chat);
        $this->em->flush();

        foreach ([['IN', 'Hello from the user'], ['OUT', 'Hello from the assistant']] as [$direction, $text]) {
            $message = (new Message())
                ->setUserId($userId)
                ->setChat($chat)
                ->setTrackingId($trackingId)
                ->setProviderIndex('MCP')
                ->setUnixTimestamp(time())
                ->setDateTime(date('YmdHis'))
                ->setMessageType('API')
                ->setFile(0)
                ->setTopic('CHAT')
                ->setLanguage('en')
                ->setText($text)
                ->setDirection($direction)
                ->setStatus('complete');
            $this->em->persist($message);
        }

        $file = (new File())
            ->setUserId($userId)
            ->setFilePath('mcp-test/doc.txt')
            ->setFileType('txt')
            ->setFileName('MCP Test Doc')
            ->setFileSize(40)
            ->setFileMime('text/plain')
            ->setFileText('The MCP test document mentions PURPLEFOX.')
            ->setStatus('vectorized');
        $this->em->persist($file);
        $this->em->flush();

        return ['chatId' => (int) $chat->getId(), 'fileId' => (int) $file->getId()];
    }

    /**
     * Runs the initialize handshake and returns the negotiated session id.
     */
    private function initialize(): string
    {
        $result = $this->jsonRpc($this->initializePayload(), null);

        self::assertArrayHasKey('result', $result, json_encode($result));
        self::assertArrayHasKey('serverInfo', $result['result']);
        self::assertSame('Synaplan', $result['result']['serverInfo']['name']);

        return (string) $this->client->getResponse()->headers->get('Mcp-Session-Id');
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function jsonRpc(array $payload, ?string $sessionId): array
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_X_API_KEY' => self::API_KEY,
            'HTTP_MCP_PROTOCOL_VERSION' => self::PROTOCOL_VERSION,
        ];
        if (null !== $sessionId) {
            $server['HTTP_MCP_SESSION_ID'] = $sessionId;
        }

        $this->client->request('POST', '/mcp', server: $server, content: (string) json_encode($payload));

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded, 'MCP response was not valid JSON: '.$this->client->getResponse()->getContent());

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function initializePayload(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'phpunit-mcp-client', 'version' => '1.0.0'],
            ],
        ];
    }

    private function createUserWithApiKey(): void
    {
        $userRepo = $this->em->getRepository(User::class);
        $user = $userRepo->findOneBy(['mail' => 'mcp-test@synaplan.internal']);

        if (null === $user) {
            $user = new User();
            $user->setMail('mcp-test@synaplan.internal');
            $user->setCreated(date('YmdHis'));
            $user->setType('WEB');
            $user->setProviderId('mcp-endpoint-test');
            $user->setUserLevel('NEW');
            $user->setEmailVerified(true);
            $this->em->persist($user);
            $this->em->flush();
        }

        $apiKeyRepo = $this->em->getRepository(ApiKey::class);
        if (null === $apiKeyRepo->findOneBy(['key' => self::API_KEY])) {
            $apiKey = new ApiKey();
            $apiKey->setOwner($user);
            $apiKey->setKey(self::API_KEY);
            $apiKey->setStatus('active');
            $apiKey->setName('MCP Endpoint Test');
            $apiKey->setScopes([]);
            $this->em->persist($apiKey);
            $this->em->flush();
        }
    }
}
