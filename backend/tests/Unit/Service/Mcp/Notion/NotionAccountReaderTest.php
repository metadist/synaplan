<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp\Notion;

use App\Entity\McpServerConfig;
use App\Service\Mcp\McpClient;
use App\Service\Mcp\McpClientException;
use App\Service\Mcp\McpOAuthState;
use App\Service\Mcp\Notion\NotionAccountReader;
use App\Service\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class NotionAccountReaderTest extends TestCase
{
    private const SELF = '{"workspace":{"id":"w1","name":"Acme"},"user":{"type":"person","id":"u1","name":"Ada Lovelace","email":"ada@example.com"}}';

    public function testParsesTheWorkspaceAndPerson(): void
    {
        $account = NotionAccountReader::parse(['content' => [['type' => 'text', 'text' => self::SELF]], 'isError' => false]);

        self::assertSame(['workspace_name' => 'Acme', 'user_name' => 'Ada Lovelace', 'user_email' => 'ada@example.com'], $account);
    }

    public function testUnreadableAnswersGiveNoAccount(): void
    {
        $empty = ['workspace_name' => null, 'user_name' => null, 'user_email' => null];

        self::assertSame($empty, NotionAccountReader::parse(['content' => [['type' => 'text', 'text' => 'not json']], 'isError' => false]));
        self::assertSame($empty, NotionAccountReader::parse(['content' => [['type' => 'text', 'text' => self::SELF]], 'isError' => true]));
        self::assertSame($empty, NotionAccountReader::parse(['content' => [], 'isError' => false]));
    }

    public function testCachesTheAnswerButNotAFailure(): void
    {
        $client = $this->createMock(McpClient::class);
        $client->expects($this->exactly(3))->method('callTool')->willReturnOnConsecutiveCalls(
            $this->throwException(new McpClientException('timeout')),
            ['content' => [['type' => 'text', 'text' => self::SELF]], 'isError' => false],
            ['content' => [['type' => 'text', 'text' => self::SELF]], 'isError' => false],
        );
        $encryption = new EncryptionService('test-secret', new NullLogger());
        $reader = new NotionAccountReader($client, new ArrayAdapter(), $encryption, new NullLogger());
        $server = $this->server($encryption, 'first');

        $failed = $reader->read($server);
        $fresh = $reader->read($server);
        $cached = $reader->read($server);
        $otherSignIn = $reader->read($this->server($encryption, 'other-sign-in'));

        self::assertNull($failed['user_email']);
        self::assertSame('ada@example.com', $fresh['user_email']);
        self::assertSame('ada@example.com', $cached['user_email']);
        self::assertSame('Acme', $otherSignIn['workspace_name']);
    }

    private function server(EncryptionService $encryption, string $token): McpServerConfig
    {
        $server = new McpServerConfig();
        $server->setUserId(1)->setName('Notion')->setUrl('https://mcp.notion.com/mcp')->setAuthMode(McpServerConfig::AUTH_MODE_OAUTH);
        (new \ReflectionProperty($server, 'id'))->setValue($server, 5);
        $server->setDecryptedOAuthState(new McpOAuthState(accessToken: $token, status: McpOAuthState::STATUS_CONNECTED), $encryption);

        return $server;
    }
}
