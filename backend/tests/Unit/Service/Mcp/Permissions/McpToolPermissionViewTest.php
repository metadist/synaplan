<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp\Permissions;

use App\Entity\McpServerConfig;
use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\Permissions\McpToolPermissions;
use App\Service\Mcp\Permissions\McpToolPermissionView;
use App\Service\Mcp\Permissions\McpToolPolicyStore;
use App\Service\Tool\Policy\GroupPolicyProviderInterface;
use App\Service\Tool\Policy\PolicyOutcome;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use PHPUnit\Framework\TestCase;

final class McpToolPermissionViewTest extends TestCase
{
    public function testRowsCarryClassOutcomeDefaultAndHints(): void
    {
        $server = $this->server('{"v":1,"tools":{"create":"approve"},"seen":["search"]}');
        $rows = $this->view()->rows($server, 7, [
            $this->tool('search', ['readOnlyHint' => true]),
            $this->tool('create', ['readOnlyHint' => false]),
            $this->tool('nuke', ['destructiveHint' => true]),
            $this->tool('mystery', []),
        ], false);

        $by = array_column($rows, null, 'name');
        self::assertSame('read', $by['search']['category']);
        self::assertFalse($by['search']['is_new']);
        self::assertSame('approve', $by['create']['outcome']);
        self::assertSame('auto', $by['create']['default_outcome']);
        self::assertTrue($by['create']['is_new']);
        self::assertSame('destructive', $by['nuke']['category']);
        self::assertSame('approve', $by['nuke']['outcome']);
        self::assertFalse($by['mystery']['hinted']);
        self::assertSame('write', $by['mystery']['category']);
        self::assertSame(['a', 'b'], $by['search']['input_names']);
        self::assertSame(2, $by['search']['input_count']);
        self::assertNull($by['search']['locked_reason']);
    }

    public function testASharedConnectionIsLocked(): void
    {
        $rows = $this->view()->rows($this->server(), 7, [$this->tool('search', ['readOnlyHint' => true])], true);

        self::assertSame('shared', $rows[0]['locked_reason']);
    }

    public function testAGroupBlockLocksTheTool(): void
    {
        $group = $this->createStub(GroupPolicyProviderInterface::class);
        $group->method('outcomeFor')->willReturnCallback(
            static fn (int $u, ToolDescriptor $t, SideEffect $c): ?PolicyOutcome => 'mcp:5:create' === $t->name ? PolicyOutcome::Block : null,
        );

        $rows = $this->view($group)->rows($this->server(), 7, [
            $this->tool('search', ['readOnlyHint' => true]),
            $this->tool('create', ['readOnlyHint' => false]),
        ], false);

        self::assertNull($rows[0]['locked_reason']);
        self::assertSame('group_policy', $rows[1]['locked_reason']);
    }

    public function testAnUnusableNotionToolIsUnavailableAndBlock(): void
    {
        $rows = $this->view()->rows($this->server('{}', 'https://mcp.notion.com/mcp'), 7, [
            $this->tool('notion-create-attachment', ['destructiveHint' => true]),
        ], false);

        self::assertSame('unavailable', $rows[0]['category']);
        self::assertSame('block', $rows[0]['outcome']);
        self::assertSame('unavailable', $rows[0]['locked_reason']);
    }

    private function view(?GroupPolicyProviderInterface $group = null): McpToolPermissionView
    {
        $permissions = new McpToolPermissions();

        return new McpToolPermissionView(
            $permissions,
            new McpToolPolicyStore($this->createStub(McpServerConfigRepository::class), $permissions),
            $group ?? $this->createStub(GroupPolicyProviderInterface::class),
        );
    }

    /**
     * @param array<string, mixed> $annotations
     *
     * @return array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>}
     */
    private function tool(string $name, array $annotations): array
    {
        return [
            'name' => $name,
            'description' => 'd',
            'inputSchema' => ['properties' => ['a' => [], 'b' => []]],
            'annotations' => $annotations,
        ];
    }

    private function server(string $policy = '{}', string $url = 'https://example.com/mcp'): McpServerConfig
    {
        $server = (new McpServerConfig())->setUserId(7)->setUrl($url)->setToolPolicy($policy);
        (new \ReflectionProperty($server, 'id'))->setValue($server, 5);

        return $server;
    }
}
