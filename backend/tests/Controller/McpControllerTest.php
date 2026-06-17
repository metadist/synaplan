<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ApiKey;
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
