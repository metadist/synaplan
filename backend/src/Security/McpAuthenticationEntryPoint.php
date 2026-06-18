<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Authentication entry point for the stateless `mcp` firewall.
 *
 * Returns a spec-compliant `401` with a `WWW-Authenticate: Bearer` challenge
 * that points MCP clients at the RFC 9728 Protected Resource Metadata document,
 * so they can discover the authorization server and start an OAuth flow.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization
 */
class McpAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly string $appUrl,
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse(
            [
                'error' => 'Authentication required',
                'code' => 'UNAUTHENTICATED',
            ],
            Response::HTTP_UNAUTHORIZED,
            [
                'WWW-Authenticate' => \sprintf(
                    'Bearer resource_metadata="%s"',
                    rtrim($this->appUrl, '/').'/.well-known/oauth-protected-resource/mcp',
                ),
            ],
        );
    }
}
