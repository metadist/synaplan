<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp\Permissions;

use App\Entity\McpServerConfig;
use App\Service\Mcp\Permissions\McpToolPermissions;
use App\Service\Tool\Policy\PolicyOutcome;
use App\Service\Tool\SideEffect;
use PHPUnit\Framework\TestCase;

final class McpToolPermissionsTest extends TestCase
{
    private McpToolPermissions $permissions;

    protected function setUp(): void
    {
        $this->permissions = new McpToolPermissions();
    }

    public function testDefaultsReadAndWriteAutoDestructiveApprove(): void
    {
        $server = $this->server();

        self::assertSame(PolicyOutcome::Auto, $this->permissions->outcome($server, 'search', SideEffect::Read));
        self::assertSame(PolicyOutcome::Auto, $this->permissions->outcome($server, 'create', SideEffect::Write));
        self::assertSame(PolicyOutcome::Approve, $this->permissions->outcome($server, 'delete', SideEffect::Destructive));
    }

    public function testAnOverrideWinsOverTheClassDefaultInBothDirections(): void
    {
        $server = $this->server('{"v":1,"tools":{"create":"approve","delete":"auto","search":"block"}}');

        self::assertSame(PolicyOutcome::Approve, $this->permissions->outcome($server, 'create', SideEffect::Write));
        self::assertSame(PolicyOutcome::Auto, $this->permissions->outcome($server, 'delete', SideEffect::Destructive));
        self::assertSame(PolicyOutcome::Block, $this->permissions->outcome($server, 'search', SideEffect::Read));
        self::assertSame(PolicyOutcome::Auto, $this->permissions->outcome($server, 'other', SideEffect::Write));
    }

    public function testUnusableNotionToolsAreAlwaysBlockEvenWithAnAutoOverride(): void
    {
        $server = $this->server('{"v":1,"tools":{"notion-create-attachment":"auto"}}', 'https://mcp.notion.com/mcp');

        self::assertSame(PolicyOutcome::Block, $this->permissions->outcome($server, 'notion-create-attachment', SideEffect::Destructive));
        self::assertSame(PolicyOutcome::Auto, $this->permissions->outcome($server, 'notion-search', SideEffect::Read));
    }

    public function testAnUnreadablePolicyFailsClosed(): void
    {
        foreach (['not json', '{"tools":"x"}', '[1'] as $raw) {
            $server = $this->server($raw);
            self::assertSame(PolicyOutcome::Approve, $this->permissions->outcome($server, 'create', SideEffect::Write), $raw);
            self::assertSame(PolicyOutcome::Approve, $this->permissions->outcome($server, 'delete', SideEffect::Destructive), $raw);
        }
    }

    public function testAnUnknownStoredValueAsksInsteadOfFallingBackToTheDefault(): void
    {
        $server = $this->server('{"v":1,"tools":{"create":"yolo","other":5}}');

        self::assertSame(PolicyOutcome::Approve, $this->permissions->outcome($server, 'create', SideEffect::Write));
        self::assertSame(PolicyOutcome::Approve, $this->permissions->outcome($server, 'other', SideEffect::Write));
        self::assertSame([], $this->permissions->overrides($server));
    }

    public function testEmptyPolicyMeansAllDefaults(): void
    {
        foreach (['', '{}'] as $raw) {
            $server = $this->server($raw);
            self::assertSame(PolicyOutcome::Auto, $this->permissions->outcome($server, 'create', SideEffect::Write));
            self::assertSame([], $this->permissions->overrides($server));
        }
    }

    private function server(string $policy = '{}', string $url = 'https://crm.example.com/mcp'): McpServerConfig
    {
        return (new McpServerConfig())->setUrl($url)->setToolPolicy($policy);
    }
}
