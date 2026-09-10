<?php

declare(strict_types=1);

namespace App\Service\Tool;

/**
 * One callable the AI can invoke. Unique {@see $name} across the registry.
 *
 * @phpstan-type ToolMeta array<string, mixed>
 */
final readonly class ToolDescriptor
{
    public const POLICY_OWN_ARTEFACT = 'own_artefact';

    /**
     * @param array<string, mixed> $inputSchema
     * @param ToolMeta             $meta
     */
    public function __construct(
        public string $name,
        public string $title,
        public string $description,
        public array $inputSchema,
        public SideEffect $sideEffect,
        public ToolSource $source,
        public int $ownerId,
        public ?string $policyException = null,
        public array $meta = [],
    ) {
    }

    /**
     * Wire name the model actually calls (gateway MCP names, document names).
     */
    public function callName(): string
    {
        $gateway = $this->meta['gatewayName'] ?? null;

        return is_string($gateway) && '' !== $gateway ? $gateway : $this->name;
    }

    /**
     * Public listing shape. Never includes {@see $meta} (C8 / serializer allow-list).
     *
     * @return array{
     *     name: string,
     *     title: string,
     *     description: string,
     *     sideEffect: string,
     *     source: string,
     *     policy: string|null,
     *     policyException: string|null,
     *     shared: bool
     * }
     */
    public function toListItem(?string $policy = null): array
    {
        return [
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'sideEffect' => $this->sideEffect->value,
            'source' => $this->source->value,
            'policy' => $policy,
            'policyException' => $this->policyException,
            'shared' => true === ($this->meta['shared'] ?? false),
        ];
    }
}
