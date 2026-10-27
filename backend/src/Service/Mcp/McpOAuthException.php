<?php

declare(strict_types=1);

namespace App\Service\Mcp;

/**
 * Expected failure during MCP OAuth discovery, registration or consent.
 * Controllers map this to a 4xx — it must never escape as a 500. The
 * message is for logs; {@see $reason} is what the person is told.
 */
final class McpOAuthException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly McpOAuthFailure $reason = McpOAuthFailure::Unknown,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
