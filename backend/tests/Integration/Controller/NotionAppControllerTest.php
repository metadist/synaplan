<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Config;
use App\Entity\McpServerConfig;
use App\Entity\User;
use App\Service\Mcp\McpOAuthState;
use App\Service\Security\EncryptionService;
use App\Tests\Trait\AuthenticatedTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Apps → Notion: hidden while the admin flag is off, then status and the
 * writing switch on a connection that belongs only to the test user.
 */
class NotionAppControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;
    private string $token;
    private User $user;

    private ?string $oauthFlagBefore = null;
    private bool $createdOauthFlag = false;

    /** @var list<int> */
    private array $createdServerIds = [];

    /** @var list<int> */
    private array $createdConfigIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = $this->client->getContainer()->get('doctrine')->getManager();

        $email = 'notion-app-itest@synaplan.test';
        $user = $em->getRepository(User::class)->findOneBy(['mail' => $email]);
        if (!$user instanceof User) {
            $user = (new User())
                ->setMail($email)
                ->setType('WEB')
                ->setProviderId('notion-app-itest')
                ->setUserLevel('PRO');
            $user->setCreated(date('YmdHis'));
            $user->setEmailVerified(true);
            $em->persist($user);
            $em->flush();
        }
        $this->user = $user;
        $this->token = $this->authenticateClient($this->client, $user);

        $flag = $em->getRepository(Config::class)->findOneBy([
            'ownerId' => 0,
            'group' => 'MCP',
            'setting' => 'OAUTH_CONNECTORS_ENABLED',
        ]);
        $this->oauthFlagBefore = $flag instanceof Config ? $flag->getValue() : null;
        $this->createdOauthFlag = !$flag instanceof Config;

        $clientFlag = (new Config())
            ->setOwnerId($user->getId())
            ->setGroup('MCP')
            ->setSetting('CLIENT_ENABLED')
            ->setValue('1');
        $em->persist($clientFlag);
        $em->flush();
        $this->createdConfigIds[] = (int) $clientFlag->getId();
    }

    protected function tearDown(): void
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $connection = $em->getConnection();
        $userId = $this->user->getId();
        $promptIds = $connection->fetchFirstColumn('SELECT BID FROM BPROMPTS WHERE BOWNERID = ?', [$userId]);
        foreach ($promptIds as $promptId) {
            $connection->executeStatement('DELETE FROM BPROMPTMETA WHERE BPROMPTID = ?', [$promptId]);
        }
        if ([] !== $promptIds) {
            $connection->executeStatement('DELETE FROM BPROMPTS WHERE BOWNERID = ?', [$userId]);
        }

        foreach ($this->createdServerIds as $id) {
            $entity = $em->find(McpServerConfig::class, $id);
            if ($entity instanceof McpServerConfig) {
                $em->remove($entity);
            }
        }
        foreach ($this->createdConfigIds as $id) {
            $entity = $em->find(Config::class, $id);
            if ($entity instanceof Config) {
                $em->remove($entity);
            }
        }

        $flag = $em->getRepository(Config::class)->findOneBy([
            'ownerId' => 0,
            'group' => 'MCP',
            'setting' => 'OAUTH_CONNECTORS_ENABLED',
        ]);
        if ($this->createdOauthFlag && $flag instanceof Config) {
            $em->remove($flag);
        } elseif ($flag instanceof Config && null !== $this->oauthFlagBefore) {
            $flag->setValue($this->oauthFlagBefore);
        }
        $em->flush();

        parent::tearDown();
    }

    public function testNotionIsAbsentWhileOauthConnectorsAreOff(): void
    {
        $this->setOauthFlag('0');

        $this->request('GET', '/api/v1/apps/notion');
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());

        $this->request('GET', '/api/v1/apps/notion/account');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testStatusWritingAndConnect(): void
    {
        $this->setOauthFlag('1');

        $empty = $this->request('GET', '/api/v1/apps/notion');
        self::assertSame('not_connected', $empty['status']);
        self::assertNull($empty['server_id']);

        $noAccount = $this->request('GET', '/api/v1/apps/notion/account');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertTrue($noAccount['success']);
        self::assertNull($noAccount['workspace_name']);
        self::assertNull($noAccount['user_email']);

        $missing = $this->request('PATCH', '/api/v1/apps/notion', ['allow_write' => true]);
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Connect Notion first', (string) $missing['error']);

        $em = $this->client->getContainer()->get('doctrine')->getManager();
        /** @var EncryptionService $encryption */
        $encryption = $this->client->getContainer()->get(EncryptionService::class);
        $server = new McpServerConfig();
        $server->setUserId($this->user->getId())
            ->setName('Notion')
            ->setUrl('https://mcp.notion.com/mcp')
            ->setAuthMode(McpServerConfig::AUTH_MODE_OAUTH)
            ->setEnabled(true);
        $server->setDecryptedOAuthState(new McpOAuthState(
            resource: 'https://mcp.notion.com/mcp',
            status: McpOAuthState::STATUS_CONNECTED,
            accessToken: 'at-test',
        ), $encryption);
        $em->persist($server);
        $em->flush();
        $this->createdServerIds[] = (int) $server->getId();

        $connected = $this->request('GET', '/api/v1/apps/notion');
        self::assertSame('connected', $connected['status']);
        self::assertFalse($connected['allow_write']);
        self::assertFalse($connected['write_auto']);

        $writing = $this->request('PATCH', '/api/v1/apps/notion', ['allow_write' => true]);
        self::assertTrue($writing['allow_write']);
        self::assertTrue($writing['write_auto']);

        $stopped = $this->request('PATCH', '/api/v1/apps/notion', ['allow_write' => false]);
        self::assertFalse($stopped['allow_write']);
        self::assertFalse($stopped['write_auto']);
    }

    private function setOauthFlag(string $value): void
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $flag = $em->getRepository(Config::class)->findOneBy([
            'ownerId' => 0,
            'group' => 'MCP',
            'setting' => 'OAUTH_CONNECTORS_ENABLED',
        ]);
        if (!$flag instanceof Config) {
            $flag = (new Config())->setOwnerId(0)->setGroup('MCP')->setSetting('OAUTH_CONNECTORS_ENABLED');
            $em->persist($flag);
            $this->createdOauthFlag = true;
        }
        $flag->setValue($value);
        $em->flush();
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
        ], [] === $payload && 'POST' !== $method ? null : (string) json_encode($payload));

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
