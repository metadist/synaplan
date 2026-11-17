<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\McpServerConfig;
use App\Entity\User;
use App\Tests\Trait\AuthenticatedTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The tool permission endpoints: owner-only writes, strict input, no access to
 * another person's connection.
 */
class McpToolPermissionControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;
    private KernelBrowser $client;
    private string $token;
    private User $user;

    /** @var list<int> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $user = $this->client->getContainer()->get('doctrine')->getRepository(User::class)->findOneBy(['mail' => 'admin@synaplan.com']);
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
        $em->flush();
        parent::tearDown();
    }

    public function testInputIsValidatedAndNothingIsWrittenOnError(): void
    {
        $id = $this->createServer((int) $this->user->getId());

        foreach ([
            [],
            ['tools' => ['ghost' => 'auto']],
            ['tools' => ['ghost' => 'yolo']],
            ['category' => 'nuclear', 'outcome' => 'auto'],
            ['category' => 'write', 'outcome' => 'maybe'],
            ['category' => 'write', 'outcome' => 'block', 'tools' => ['ghost' => 'auto']],
        ] as $body) {
            $this->request('PUT', "/api/v1/mcp-servers/{$id}/tool-permissions", $body);
            self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->httpStatus(), (string) json_encode($body));
        }

        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $em->clear();
        self::assertSame('{}', $em->find(McpServerConfig::class, $id)?->getToolPolicy());
    }

    public function testAnotherPersonsConnectionIsNotFound(): void
    {
        $id = $this->createServer((int) $this->user->getId() + 999999);

        $statuses = [];
        foreach ([
            ['GET', "/api/v1/mcp-servers/{$id}/tools", []],
            ['PUT', "/api/v1/mcp-servers/{$id}/tool-permissions", ['category' => 'write', 'outcome' => 'block']],
            ['POST', "/api/v1/mcp-servers/{$id}/tool-permissions/seen", []],
        ] as [$method, $uri, $body]) {
            $this->request($method, $uri, $body);
            $statuses[] = $this->httpStatus();
        }

        self::assertSame([404, 404, 404], $statuses);
    }

    public function testAnOwnConnectionListsAsEditable(): void
    {
        $id = $this->createServer((int) $this->user->getId());

        $list = $this->request('GET', "/api/v1/mcp-servers/{$id}/tools");

        self::assertSame(Response::HTTP_OK, $this->httpStatus());
        self::assertTrue($list['can_edit']);
        self::assertIsArray($list['tools']);
    }

    public function testUnauthenticatedRequestsAreRejected(): void
    {
        $this->client->getCookieJar()->clear();

        $this->client->request('PUT', '/api/v1/mcp-servers/1/tool-permissions');
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->httpStatus());
    }

    private function httpStatus(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    private function createServer(int $userId): int
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $server = (new McpServerConfig())->setUserId($userId)->setName('perm-test')->setUrl('https://perm-test.example.com/mcp');
        $em->persist($server);
        $em->flush();
        $this->createdIds[] = (int) $server->getId();

        return (int) $server->getId();
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
}
