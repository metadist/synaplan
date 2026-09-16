<?php

declare(strict_types=1);

namespace App\Service\Mcp;

/**
 * Result of RFC 9728 + RFC 8414 discovery against one MCP resource URL.
 *
 * @phpstan-type ScopeList list<string>
 */
final readonly class McpOAuthDiscoveryResult
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public string $resource,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public string $registrationEndpoint,
        public array $scopes,
        public bool $supportsRefreshGrant,
    ) {
    }
}
