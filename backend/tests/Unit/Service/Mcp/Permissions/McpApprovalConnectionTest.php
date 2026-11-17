<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp\Permissions;

use App\Entity\McpServerConfig;
use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\Permissions\McpApprovalConnection;
use PHPUnit\Framework\TestCase;

final class McpApprovalConnectionTest extends TestCase
{
    public function testNamesTheViewersOwnConnectionAndLinksItsPermissions(): void
    {
        $server = (new McpServerConfig())->setUserId(7)->setName('Notion')->setUrl('https://mcp.notion.com/mcp');
        $servers = $this->createMock(McpServerConfigRepository::class);
        $servers->expects(self::once())->method('findByIdAndUser')->with(12, 7)->willReturn($server);

        $row = (new McpApprovalConnection($servers))->decorate(['tool' => 'mcp:12:search'], 7);

        self::assertSame(['id' => 12, 'name' => 'Notion', 'permissions_path' => '/apps/notion?tab=permissions'], $row['connection']);
    }

    public function testAnotherPersonsConnectionIsNeverRevealed(): void
    {
        $servers = $this->createStub(McpServerConfigRepository::class);
        $servers->method('findByIdAndUser')->willReturn(null);

        $row = (new McpApprovalConnection($servers))->decorate(['tool' => 'mcp:12:search'], 8);

        self::assertArrayNotHasKey('connection', $row);
    }

    public function testANonMcpToolIsLeftAlone(): void
    {
        $servers = $this->createMock(McpServerConfigRepository::class);
        $servers->expects(self::never())->method('findByIdAndUser');
        $decorator = new McpApprovalConnection($servers);

        self::assertSame(['tool' => 'agent.publish'], $decorator->decorate(['tool' => 'agent.publish'], 7));
        self::assertSame(['tool' => 'mcp:x:y'], $decorator->decorate(['tool' => 'mcp:x:y'], 7));
        self::assertSame([], $decorator->decorate([], 7));
    }
}
