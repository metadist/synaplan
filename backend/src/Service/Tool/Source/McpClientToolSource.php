<?php

declare(strict_types=1);

namespace App\Service\Tool\Source;

use App\AI\Messages\Mcp\McpToolCatalogAdapter;
use App\Service\Mcp\McpToolRegistry;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolSource;
use App\Service\Tool\ToolSourceInterface;

final readonly class McpClientToolSource implements ToolSourceInterface
{
    public function __construct(
        private McpToolRegistry $mcpToolRegistry,
        private McpToolCatalogAdapter $catalogAdapter,
    ) {
    }

    public function source(): ToolSource
    {
        return ToolSource::Mcp;
    }

    public function describe(int $userId, array $context = []): array
    {
        if ($userId < 1) {
            return [];
        }

        $descriptors = [];
        foreach ($this->mcpToolRegistry->catalogForUser($userId) as $entry) {
            $server = $entry['server'];
            $serverId = (int) $server->getId();
            if ($serverId < 1) {
                continue;
            }
            foreach ($entry['tools'] as $tool) {
                $annotations = $tool['annotations'];
                $readOnly = isset($annotations['readOnlyHint']) ? (bool) $annotations['readOnlyHint'] : null;
                $destructive = isset($annotations['destructiveHint']) ? (bool) $annotations['destructiveHint'] : null;
                $gatewayName = $this->catalogAdapter->namespace($serverId, (string) $tool['name']);
                $schema = $tool['inputSchema'];
                $descriptors[] = new ToolDescriptor(
                    name: sprintf('mcp:%d:%s', $serverId, (string) $tool['name']),
                    title: (string) $tool['name'],
                    description: $tool['description'],
                    inputSchema: $schema,
                    sideEffect: SideEffect::fromHints($readOnly, $destructive),
                    source: ToolSource::Mcp,
                    ownerId: $userId,
                    meta: [
                        'serverId' => $serverId,
                        'tool' => (string) $tool['name'],
                        'allowWrite' => $server->allowsWrite(),
                        'gatewayName' => $gatewayName,
                        'annotations' => $annotations,
                    ],
                );
            }
        }

        return $descriptors;
    }
}
