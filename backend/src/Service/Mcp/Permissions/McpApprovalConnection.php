<?php

declare(strict_types=1);

namespace App\Service\Mcp\Permissions;

use App\Repository\McpServerConfigRepository;

/**
 * Names the connection behind an MCP approval and says where its
 * permissions are changed. Only the viewer's own connection is ever
 * resolved, so a row never reveals another person's connection.
 */
final readonly class McpApprovalConnection
{
    public function __construct(private McpServerConfigRepository $servers)
    {
    }

    /**
     * @param array<string, mixed> $row an approval row with at least `tool`
     *
     * @return array<string, mixed> the row, plus `connection` for an MCP tool of the viewer
     */
    public function decorate(array $row, int $viewerId): array
    {
        $tool = $row['tool'] ?? null;
        if (!is_string($tool) || 1 !== preg_match('/^mcp:(\d+):/', $tool, $match)) {
            return $row;
        }
        $serverId = (int) $match[1];
        $server = $serverId > 0 ? $this->servers->findByIdAndUser($serverId, $viewerId) : null;
        if (null === $server) {
            return $row;
        }
        $row['connection'] = [
            'id' => $serverId,
            'name' => $server->getName(),
            'permissions_path' => McpPermissionsPath::for($serverId, $server->getUrl()),
        ];

        return $row;
    }
}
