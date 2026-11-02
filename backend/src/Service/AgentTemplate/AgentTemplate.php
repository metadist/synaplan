<?php

declare(strict_types=1);

namespace App\Service\AgentTemplate;

/**
 * One built-in assistant template: a bundle plus the template block.
 */
final readonly class AgentTemplate
{
    /**
     * @param list<array{kind: string, value: string|null, hintKey: string|null, optional: bool}> $requires
     * @param list<string>                                                                        $tryIt
     */
    public function __construct(
        public string $id,
        public string $version,
        public string $titleKey,
        public string $summaryKey,
        public string $category,
        public array $requires,
        public array $tryIt,
        public AgentTemplateBody $body,
    ) {
    }
}
