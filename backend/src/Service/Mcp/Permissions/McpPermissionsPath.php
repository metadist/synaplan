<?php

declare(strict_types=1);

namespace App\Service\Mcp\Permissions;

use App\Service\Mcp\Notion\NotionToolHints;

/**
 * Where the owner changes the permissions of one connection. Notion has its
 * own app page; every other connection uses the MCP page.
 */
final class McpPermissionsPath
{
    public static function for(int $serverId, string $serverUrl): string
    {
        return NotionToolHints::isNotion($serverUrl)
            ? '/apps/notion?tab=permissions'
            : sprintf('/apps/mcp?server=%d&tab=permissions', $serverId);
    }
}
