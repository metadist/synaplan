<?php

declare(strict_types=1);

namespace App\Service\Mcp;

/**
 * Raised by {@see McpClient} for any expected outbound-MCP failure (blocked
 * target, timeout, HTTP error, malformed JSON-RPC, tool error). Callers turn
 * it into a graceful degradation (a failed node / a 4xx API response) — it
 * must never escape as a 500.
 */
final class McpClientException extends \RuntimeException
{
}
