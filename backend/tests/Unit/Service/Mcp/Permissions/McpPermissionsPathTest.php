<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp\Permissions;

use App\Service\Mcp\Permissions\McpPermissionsPath;
use PHPUnit\Framework\TestCase;

final class McpPermissionsPathTest extends TestCase
{
    public function testNotionOpensItsAppPanel(): void
    {
        self::assertStringStartsWith('/apps/notion', McpPermissionsPath::for(7, 'https://mcp.notion.com/mcp'));
        self::assertStringContainsString('permissions', McpPermissionsPath::for(7, 'https://mcp.notion.com/mcp'));
    }

    public function testAnyOtherConnectionOpensItsOwnPermissions(): void
    {
        $path = McpPermissionsPath::for(7, 'https://example.com/mcp');

        self::assertStringContainsString('server=7', $path);
        self::assertStringContainsString('permissions', $path);
    }
}
