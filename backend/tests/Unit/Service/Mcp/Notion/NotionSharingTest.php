<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp\Notion;

use App\Entity\McpServerConfig;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\McpServerConfigRepository;
use App\Repository\UserRepository;
use App\Service\Mcp\McpOAuthState;
use App\Service\Mcp\Notion\NotionSharing;
use App\Service\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class NotionSharingTest extends TestCase
{
    private EncryptionService $encryption;

    protected function setUp(): void
    {
        $this->encryption = new EncryptionService('test-secret', new NullLogger());
    }

    public function testMembersReadThroughTheAdminsConnectionButTheAdminUsesTheirOwn(): void
    {
        $server = $this->server(connected: true);
        $sharing = $this->sharing($server, $this->user(true));

        self::assertSame($server, $sharing->sharedServer(8));
        self::assertNull($sharing->sharedServer(4));
        self::assertSame(['server_id' => 12, 'owner_name' => 'Ada'], $sharing->describe());
    }

    public function testAnExpiredConnectionIsNotShared(): void
    {
        $sharing = $this->sharing($this->server(connected: false), $this->user(true));

        self::assertNull($sharing->sharedServer(8));
    }

    public function testAConnectionStopsBeingSharedWhenTheOwnerIsNoLongerAdmin(): void
    {
        $sharing = $this->sharing($this->server(connected: true), $this->user(false));

        self::assertNull($sharing->sharedServer(8));
    }

    public function testOnlyAnAdminCanShareTheirOwnConnection(): void
    {
        $sharing = $this->sharing($this->server(connected: true), $this->user(false));

        $this->expectException(\InvalidArgumentException::class);
        $sharing->share($this->user(false), $this->server(connected: true), true);
    }

    private function sharing(McpServerConfig $server, User $owner): NotionSharing
    {
        $config = $this->createStub(ConfigRepository::class);
        $config->method('getValue')->willReturn('12');
        $servers = $this->createStub(McpServerConfigRepository::class);
        $servers->method('find')->willReturn($server);
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn($owner);

        return new NotionSharing($config, $servers, $users, $this->encryption);
    }

    private function server(bool $connected): McpServerConfig
    {
        $server = new McpServerConfig();
        $server->setUserId(4)->setName('Notion')->setUrl('https://mcp.notion.com/mcp')
            ->setAuthMode(McpServerConfig::AUTH_MODE_OAUTH)->setEnabled(true);
        (new \ReflectionProperty($server, 'id'))->setValue($server, 12);
        $server->setDecryptedOAuthState(new McpOAuthState(
            status: $connected ? McpOAuthState::STATUS_CONNECTED : McpOAuthState::STATUS_REAUTH_REQUIRED,
            accessToken: 'at',
        ), $this->encryption);

        return $server;
    }

    private function user(bool $admin): User
    {
        $user = $this->createStub(User::class);
        $user->method('isAdmin')->willReturn($admin);
        $user->method('getId')->willReturn(4);
        $user->method('getDisplayName')->willReturn('Ada');

        return $user;
    }
}
