<?php

declare(strict_types=1);

namespace App\Service\AgentTemplate;

/**
 * How many assistants and instructions a template bundle will create.
 */
final readonly class AgentTemplateCreates
{
    public function __construct(
        public int $assistants,
        public int $prompts,
    ) {
    }

    /**
     * @param list<array{kind: string, version: int, items: list<array<string, mixed>>}> $sections
     */
    public static function fromSections(array $sections): self
    {
        $assistants = 0;
        $prompts = 0;
        foreach ($sections as $section) {
            $count = count($section['items']);
            if ('agents' === $section['kind']) {
                $assistants += $count;
            }
            if ('prompts' === $section['kind']) {
                $prompts += $count;
            }
        }

        return new self($assistants, $prompts);
    }
}
