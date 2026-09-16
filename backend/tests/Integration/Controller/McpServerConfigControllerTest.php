<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Config;
use App\Entity\McpServerConfig;
use App\Entity\User;
use App\Tests\Trait\AuthenticatedTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settings → Connections → MCP servers CRUD contract: user-scoped access,
 * SSRF-validated URLs, and the write-only auth secret (never serialized).
 */
class McpServerConfigControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;
    private KernelBrowser $client;
    private string $token;
    private User $user;

    /** @var list<int> */
    private array $createdIds = [];

    /** @var list<int> */
    private array $createdConfigIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $userRepository = $this->client->getContainer()->get('doctrine')->getRepository(User::class);
        $user = $userRepository->findOneBy(['mail' => 'admin@synaplan.com']);
        if (!$user) {
            self::markTestSkipped('Test user admin@synaplan.com not found. Run fixtures first.');
        }

        $this->user = $user;
        $this->token = $this->authenticateClient($this->client, $user);
    }

    protected function tearDown(): void
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        foreach ($this->createdIds as $id) {
            $entity = $em->find(McpServerConfig::class, $id);
            if ($entity) {
                $em->remove($entity);
            }
        }
        foreach ($this->createdConfigIds as $id) {
            $entity = $em->find(Config::class, $id);
            if ($entity) {
                $em->remove($entity);
            }
        }
        $em->flush();

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $uri, array $payload = []): array
    {
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ], [] === $payload ? null : (string) json_encode($payload));

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function testCreateListUpdateDeleteRoundTripNeverLeaksTheSecret(): void
    {
        $data = $this->request('POST', '/api/v1/mcp-servers', [
            'name' => 'CRM (integration test)',
            'url' => 'https://crm.example.com/mcp',
            'auth_header' => 'X-API-KEY',
            'auth_token' => 'super-secret-value',
            'enabled' => true,
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
        self::assertTrue($data['success']);
        self::assertFalse($data['server']['allow_write'], 'writes are opt-in — a new server is read-only');
        $serverId = (int) $data['server']['id'];
        $this->createdIds[] = $serverId;

        // The secret must never appear in any response payload.
        self::assertTrue($data['server']['has_auth_token']);
        self::assertStringNotContainsString('super-secret-value', (string) $this->client->getResponse()->getContent());
        // …and must be encrypted at rest.
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $stored = $em->find(McpServerConfig::class, $serverId);
        self::assertNotNull($stored);
        self::assertNotSame('super-secret-value', $stored->getAuthToken());
        self::assertNotSame('', $stored->getAuthToken());

        $list = $this->request('GET', '/api/v1/mcp-servers');
        self::assertTrue($list['success']);
        self::assertArrayHasKey('client_enabled', $list);
        $ids = array_map(static fn (array $s): int => (int) $s['id'], $list['servers']);
        self::assertContains($serverId, $ids);

        $updated = $this->request('PATCH', "/api/v1/mcp-servers/{$serverId}", [
            'name' => 'CRM renamed',
            'enabled' => false,
            'allow_write' => true,
        ]);
        self::assertTrue($updated['success']);
        self::assertSame('CRM renamed', $updated['server']['name']);
        self::assertFalse($updated['server']['enabled']);
        self::assertTrue($updated['server']['allow_write'], 'the write opt-in round-trips through PATCH');
        // Auth token untouched when the key is absent from the payload.
        self::assertTrue($updated['server']['has_auth_token']);

        $deleted = $this->request('DELETE', "/api/v1/mcp-servers/{$serverId}");
        self::assertTrue($deleted['success']);
        $this->createdIds = [];
    }

    public function testPrivateUrlIsRejected(): void
    {
        $data = $this->request('POST', '/api/v1/mcp-servers', [
            'name' => 'Evil',
            'url' => 'http://169.254.169.254/mcp',
        ]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        self::assertFalse($data['success']);
        self::assertStringContainsString('not allowed', (string) $data['error']);
    }

    public function testForeignServersAreInvisible(): void
    {
        // Create a row owned by ANOTHER user directly in the DB.
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $foreign = new McpServerConfig();
        $foreign->setUserId($this->user->getId() + 999999)
            ->setName('foreign')
            ->setUrl('https://foreign.example.com/mcp');
        $em->persist($foreign);
        $em->flush();
        $this->createdIds[] = (int) $foreign->getId();

        $list = $this->request('GET', '/api/v1/mcp-servers');
        $ids = array_map(static fn (array $s): int => (int) $s['id'], $list['servers']);
        self::assertNotContains((int) $foreign->getId(), $ids, 'another user\'s connection must be invisible');

        $this->request('DELETE', '/api/v1/mcp-servers/'.$foreign->getId());
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testConnectionTestIsBlockedWhileClientDisabled(): void
    {
        // Pin the master switch OFF for THIS user explicitly. The test used
        // to rely on "no MCP.CLIENT_ENABLED row exists → built-in default
        // OFF", which broke the moment McpConfigSeeder started seeding the
        // global row to 1 in CI's `app:seed` step (green locally, red in CI
        // — the classic ambient-state trap). A per-user row overrides the
        // seeded global row, so this is deterministic in every environment.
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $optOut = (new Config())
            ->setOwnerId((int) $this->user->getId())
            ->setGroup('MCP')
            ->setSetting('CLIENT_ENABLED')
            ->setValue('0');
        $em->persist($optOut);
        $em->flush();
        $this->createdConfigIds[] = (int) $optOut->getId();

        $created = $this->request('POST', '/api/v1/mcp-servers', [
            'name' => 'Gated',
            'url' => 'https://gated.example.com/mcp',
        ]);
        $serverId = (int) $created['server']['id'];
        $this->createdIds[] = $serverId;

        $test = $this->request('POST', "/api/v1/mcp-servers/{$serverId}/test");
        self::assertFalse($test['success']);
        self::assertStringContainsString('disabled', (string) $test['error']);

        $tools = $this->request('GET', "/api/v1/mcp-servers/{$serverId}/tools");
        self::assertFalse($tools['success']);
        self::assertSame([], $tools['tools']);
    }

    public function testOauthCreateAndStartAreBlockedWhileFlagIsOff(): void
    {
        $created = $this->request('POST', '/api/v1/mcp-servers', [
            'name' => 'Notion',
            'url' => 'https://mcp.notion.com/mcp',
            'auth_mode' => 'oauth',
        ]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        self::assertFalse($created['success']);
        self::assertStringContainsString('disabled by an administrator', (string) $created['error']);

        $bearer = $this->request('POST', '/api/v1/mcp-servers', [
            'name' => 'CRM oauth-gate',
            'url' => 'https://crm.example.com/mcp',
        ]);
        $serverId = (int) $bearer['server']['id'];
        $this->createdIds[] = $serverId;

        $start = $this->request('POST', "/api/v1/mcp-servers/{$serverId}/oauth/start");
        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('disabled by an administrator', (string) $start['error']);
    }

    public function testOauthCallbackWithBadStateRedirectsWithError(): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/v1/mcp-servers/oauth/callback?code=x&state=not-a-valid-state');

        $response = $this->client->getResponse();
        self::assertTrue($response->isRedirection());
        // Flag ships off, so the public callback refuses the exchange first.
        self::assertStringContainsString('oauth_error=disabled', (string) $response->headers->get('Location'));
    }

    public function testListReportsOauthConnectorsFlag(): void
    {
        $list = $this->request('GET', '/api/v1/mcp-servers');
        self::assertTrue($list['success']);
        self::assertArrayHasKey('oauth_connectors_enabled', $list);
        self::assertFalse($list['oauth_connectors_enabled']);
    }

    public function testUnauthenticatedRequestsAreRejected(): void
    {
        // setUp authenticates the shared client with a session cookie too —
        // drop it so this request is genuinely anonymous.
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', '/api/v1/mcp-servers');
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }
}
