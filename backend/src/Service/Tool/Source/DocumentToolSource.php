<?php

declare(strict_types=1);

namespace App\Service\Tool\Source;

use App\Service\Document\Tool\DocumentToolRegistry;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolSource;
use App\Service\Tool\ToolSourceInterface;

final readonly class DocumentToolSource implements ToolSourceInterface
{
    public function __construct(
        private DocumentToolRegistry $documentToolRegistry,
    ) {
    }

    public function source(): ToolSource
    {
        return ToolSource::Document;
    }

    public function describe(int $userId, array $context = []): array
    {
        $kind = is_string($context['documentKind'] ?? null) ? (string) $context['documentKind'] : null;
        $kinds = null !== $kind && '' !== $kind ? [$kind] : ['docx', 'xlsx', 'pptx'];
        $seen = [];
        $descriptors = [];
        foreach ($kinds as $documentKind) {
            foreach ($this->documentToolRegistry->forKind($documentKind) as $tool) {
                $name = $tool->name();
                if (isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;
                $declaration = $tool->declaration();
                $function = $declaration['function'];
                $descriptors[] = new ToolDescriptor(
                    name: $name,
                    title: $name,
                    description: $function['description'],
                    inputSchema: $function['parameters'],
                    sideEffect: $this->sideEffectFor($name),
                    source: ToolSource::Document,
                    ownerId: $userId,
                    policyException: ToolDescriptor::POLICY_OWN_ARTEFACT,
                    meta: ['kind' => $documentKind, 'appliesTo' => $tool->appliesTo()],
                );
            }
        }

        return $descriptors;
    }

    private function sideEffectFor(string $name): SideEffect
    {
        if (str_starts_with($name, 'Read')) {
            return SideEffect::Read;
        }
        if (str_starts_with($name, 'Delete')) {
            return SideEffect::Destructive;
        }

        return SideEffect::Write;
    }
}
