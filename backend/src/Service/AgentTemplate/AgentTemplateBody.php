<?php

declare(strict_types=1);

namespace App\Service\AgentTemplate;

/**
 * The assistant a template installs: name, text, and definition.
 */
final readonly class AgentTemplateBody
{
    /**
     * @param array<string, mixed> $definition
     */
    public function __construct(
        public string $name,
        public ?string $description,
        public string $icon,
        public string $instruction,
        public array $definition,
    ) {
    }
}
