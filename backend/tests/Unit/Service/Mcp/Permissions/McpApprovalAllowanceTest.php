<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp\Permissions;

use App\Entity\Approval;
use App\Entity\McpServerConfig;
use App\Entity\User;
use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\McpToolRegistry;
use App\Service\Mcp\Permissions\McpApprovalAllowance;
use App\Service\Mcp\Permissions\McpToolPermissions;
use App\Service\Mcp\Permissions\McpToolPermissionView;
use App\Service\Mcp\Permissions\McpToolPolicyStore;
use App\Service\Tool\Policy\GroupPolicyProviderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class McpApprovalAllowanceTest extends TestCase
{
    public function testAlwaysAllowSetsAWriteToolToAutoOnItsConnection(): void
    {
        $server = $this->server('{"v":1,"tools":{"create":"approve"},"seen":[]}');

        self::assertTrue($this->allowance($server)->grant($this->approval('mcp:5:create', 'write'), $this->user(7)));

        self::assertSame('{"v":1,"tools":{},"seen":[]}', $server->getToolPolicy());
    }

    public function testADestructiveApprovalIsNeverAlwaysAllowed(): void
    {
        $server = $this->server();

        self::assertFalse($this->allowance($server)->grant($this->approval('mcp:5:delete', 'destructive'), $this->user(7)));
        self::assertSame('{}', $server->getToolPolicy());
    }

    public function testAToolBlockedSinceCannotBeLoosened(): void
    {
        $server = $this->server('{"v":1,"tools":{"create":"block"},"seen":[]}');

        self::assertFalse($this->allowance($server)->grant($this->approval('mcp:5:create', 'write'), $this->user(7)));
        self::assertStringContainsString('"create":"block"', $server->getToolPolicy());
    }

    public function testAnotherPersonsConnectionIsNotTouched(): void
    {
        $server = $this->server('{"v":1,"tools":{"create":"approve"},"seen":[]}');

        self::assertFalse($this->allowance($server)->grant($this->approval('mcp:5:create', 'write'), $this->user(8)));
        self::assertStringContainsString('"create":"approve"', $server->getToolPolicy());
    }

    public function testOnlyMcpToolNamesQualify(): void
    {
        self::assertFalse($this->allowance($this->server())->grant($this->approval('email.send', 'write'), $this->user(7)));
    }

    public function testAFailingSaveIsLoggedAndDoesNotEscape(): void
    {
        $server = $this->server('{"v":1,"tools":{"create":"approve"},"seen":[]}');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $allowance = $this->allowance($server, $logger, new \RuntimeException('database gone'));

        self::assertFalse($allowance->grant($this->approval('mcp:5:create', 'write'), $this->user(7)));
    }

    private function allowance(McpServerConfig $server, ?LoggerInterface $logger = null, ?\Throwable $saveFails = null): McpApprovalAllowance
    {
        $servers = $this->createStub(McpServerConfigRepository::class);
        if (null !== $saveFails) {
            $servers->method('save')->willThrowException($saveFails);
        }
        $servers->method('findByIdAndUser')->willReturnCallback(
            static fn (int $id, int $userId): ?McpServerConfig => 5 === $id && $server->getUserId() === $userId ? $server : null,
        );
        $registry = $this->createStub(McpToolRegistry::class);
        $registry->method('toolsFor')->willReturn([
            ['name' => 'create', 'description' => '', 'inputSchema' => [], 'annotations' => ['readOnlyHint' => false]],
            ['name' => 'delete', 'description' => '', 'inputSchema' => [], 'annotations' => ['destructiveHint' => true]],
        ]);
        $permissions = new McpToolPermissions();
        $store = new McpToolPolicyStore($servers, $permissions);

        return new McpApprovalAllowance(
            $servers,
            $registry,
            $store,
            new McpToolPermissionView($permissions, $store, $this->createStub(GroupPolicyProviderInterface::class)),
            $permissions,
            $logger ?? new NullLogger(),
        );
    }

    private function approval(string $tool, string $class): Approval
    {
        return new Approval(7, 'chat:1', $tool, $class, time() + 3600);
    }

    private function user(int $id): User
    {
        $user = new User();
        (new \ReflectionProperty($user, 'id'))->setValue($user, $id);

        return $user;
    }

    private function server(string $policy = '{}'): McpServerConfig
    {
        $server = (new McpServerConfig())->setUserId(7)->setUrl('https://example.com/mcp')->setToolPolicy($policy);
        (new \ReflectionProperty($server, 'id'))->setValue($server, 5);

        return $server;
    }
}
