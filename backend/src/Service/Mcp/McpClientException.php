<?php

declare(strict_types=1);

namespace App\Service\Mcp;

/**
 * Raised by {@see McpClient} for any expected outbound-MCP failure (blocked
 * target, timeout, HTTP error, malformed JSON-RPC, tool error). Callers turn
 * it into a graceful degradation (a failed node / a 4xx API response) — it
 * must never escape as a 500.
 */
class McpClientException extends \RuntimeException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
