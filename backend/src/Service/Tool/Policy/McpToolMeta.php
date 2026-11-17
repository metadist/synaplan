<?php

declare(strict_types=1);

namespace App\Service\Tool\Policy;

/**
 * Keys of {@see \App\Service\Tool\ToolDescriptor::$meta} that the policy
 * reads for an MCP tool. Set by McpClientToolSource only.
 */
final class McpToolMeta
{
    /** The connection's choice for this tool: `auto`, `approve` or `block`. */
    public const CONNECTION_OUTCOME = 'connectionOutcome';

    /** URL of the MCP server, to recognise calls whose arguments delete content. */
    public const SERVER_URL = 'serverUrl';

    private function __construct()
    {
    }
}
