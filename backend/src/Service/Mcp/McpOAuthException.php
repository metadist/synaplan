<?php

declare(strict_types=1);

namespace App\Service\Mcp;

/**
 * Expected failure during MCP OAuth discovery, registration or consent.
 * Controllers map this to a 4xx — it must never escape as a 500.
 */
final class McpOAuthException extends \RuntimeException
{
}
