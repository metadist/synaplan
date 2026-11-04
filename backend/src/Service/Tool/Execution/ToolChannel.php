<?php

declare(strict_types=1);

namespace App\Service\Tool\Execution;

/**
 * Who is running the tool. Executors use it to keep each caller's
 * result text.
 */
final class ToolChannel
{
    public const CHAT = 'chat';
    public const MESSAGES = 'messages_api';
    public const OPENAI = 'openai_api';
    public const SAVED_TASK = 'saved_task';
    public const MCP_FETCH = 'mcp_fetch';
    public const MCP_ACTION = 'mcp_action';
    public const RESUME = 'resume';
    public const MCP_SERVER = 'mcp_server';
    public const CODE_RUN = 'code_run';
}
