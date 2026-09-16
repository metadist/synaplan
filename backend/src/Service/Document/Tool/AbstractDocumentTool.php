<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

abstract class AbstractDocumentTool implements DocumentToolInterface
{
    /**
     * @param array<string, mixed> $properties
     * @param list<string>         $required
     *
     * @return array{type: 'function', function: array{name: string, description: string, parameters: array<string, mixed>}}
     */
    protected function fn(string $name, string $description, array $properties, array $required = []): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => $required,
                ],
            ],
        ];
    }
}
