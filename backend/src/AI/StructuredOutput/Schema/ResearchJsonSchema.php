<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput\Schema;

/**
 * Shared JSON Schema pieces for the research prompts. Every object refuses
 * unknown fields, so a model cannot smuggle an extra instruction in.
 */
final class ResearchJsonSchema
{
    /**
     * @param array<string, mixed> $properties
     * @param list<string>         $required
     *
     * @return array<string, mixed>
     */
    public static function object(array $properties, array $required): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => $properties,
            'required' => $required,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function string(): array
    {
        return ['type' => 'string'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function nullableString(): array
    {
        return ['type' => ['string', 'null']];
    }

    /**
     * @param list<string> $values
     *
     * @return array<string, mixed>
     */
    public static function enum(array $values): array
    {
        return ['type' => 'string', 'enum' => $values];
    }

    /**
     * @param array<string, mixed> $items
     *
     * @return array<string, mixed>
     */
    public static function listOf(array $items, ?int $max = null): array
    {
        $schema = ['type' => 'array', 'items' => $items];
        if (null !== $max) {
            $schema['maxItems'] = $max;
        }

        return $schema;
    }
}
