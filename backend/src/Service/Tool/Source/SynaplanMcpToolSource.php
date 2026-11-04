<?php

declare(strict_types=1);

namespace App\Service\Tool\Source;

use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolSource;
use App\Service\Tool\ToolSourceInterface;

/**
 * Synaplan's own MCP server tools that change the user's data.
 *
 * They are the user's own records, so the policy is auto: the gate runs
 * and the call is audited, and nobody is asked who was not asked before.
 */
final readonly class SynaplanMcpToolSource implements ToolSourceInterface
{
    public function source(): ToolSource
    {
        return ToolSource::Plugin;
    }

    public function describe(int $userId, array $context = []): array
    {
        unset($context);
        if ($userId < 1) {
            return [];
        }

        return [
            $this->tool($userId, 'memory_add', 'Save a memory', 'Store one memory the user asked to keep.'),
            $this->tool($userId, 'file_ingest', 'Add a file', 'Add a text document to the user\'s knowledge base.'),
        ];
    }

    private function tool(int $userId, string $name, string $title, string $description): ToolDescriptor
    {
        return new ToolDescriptor(
            name: $name,
            title: $title,
            description: $description,
            inputSchema: ['type' => 'object'],
            sideEffect: SideEffect::Write,
            source: ToolSource::Plugin,
            ownerId: $userId,
            policyException: ToolDescriptor::POLICY_OWN_ARTEFACT,
        );
    }
}
