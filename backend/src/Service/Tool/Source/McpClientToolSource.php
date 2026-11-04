<?php

declare(strict_types=1);

namespace App\Service\Tool\Source;

use App\AI\Messages\Mcp\McpToolCatalogAdapter;
use App\Entity\McpServerConfig;
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
                        'serverName' => $server->getName(),
                        'tool' => (string) $tool['name'],
                        'allowWrite' => $server->allowsWrite(),
                        'writeAuto' => $server->isWriteAuto(),
                        'gatewayName' => $gatewayName,
                        'annotations' => $annotations,
                    ],
                );
            }
        }
        foreach ($this->mcpToolRegistry->sharedCatalogFor($userId) as $entry) {
            array_push($descriptors, ...$this->sharedReads($userId, $entry['server'], $entry['tools']));
        }

        return $descriptors;
    }

    /**
     * A shared connection is read-only. Mutating tools are not published,
     * so the gate cannot treat them as an allowed write.
     *
     * @param list<array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>}> $tools
     *
     * @return list<ToolDescriptor>
     */
    private function sharedReads(int $userId, McpServerConfig $server, array $tools): array
    {
        $serverId = (int) $server->getId();
        if ($serverId < 1) {
            return [];
        }
        $descriptors = [];
        foreach ($tools as $tool) {
            $annotations = $tool['annotations'];
            if (false === ($annotations['readOnlyHint'] ?? null) || true === ($annotations['destructiveHint'] ?? null)) {
                continue;
            }
            $name = (string) $tool['name'];
            $descriptors[] = new ToolDescriptor(
                name: sprintf('mcp:%d:%s', $serverId, $name),
                title: $name,
                description: $tool['description'],
                inputSchema: $tool['inputSchema'],
                sideEffect: SideEffect::Read,
                source: ToolSource::Mcp,
                ownerId: $userId,
                meta: [
                    'serverId' => $serverId,
                    'serverName' => $server->getName(),
                    'tool' => $name,
                    'allowWrite' => false,
                    'writeAuto' => false,
                    'shared' => true,
                    'gatewayName' => $this->catalogAdapter->namespace($serverId, $name),
                    'annotations' => $annotations,
                ],
            );
        }

        return $descriptors;
    }
}
