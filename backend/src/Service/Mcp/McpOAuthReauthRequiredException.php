<?php

declare(strict_types=1);

namespace App\Service\Mcp;

/**
 * The stored OAuth grant can no longer be refreshed. The owner must click
 * Connect again. Used for Higgsfield-style servers that issue no refresh token.
 */
final class McpOAuthReauthRequiredException extends McpClientException
{
}
