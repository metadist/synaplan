<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\StructuredOutputSchema;

/**
 * JSON schema for {@see \App\Service\MemoryExtractionService}'s
 * create/update/delete memory-action call.
 *
 * The model's natural output is a JSON ARRAY of actions, but OpenAI-dialect
 * structured output (and Anthropic tool-forcing, whose tool `input` is
 * always an object) both require an `object` root — a bare top-level array
 * is rejected. The array is therefore wrapped under a `memories` property,
 * which {@see \App\Service\MemoryExtractionService::parseMemoriesFromResponse()}
 * reads by key, falling back to a bare array for providers answering on the
 * prose-instruction path.
 *
 * `memory_id`/`category`/`key`/`value` are modelled as nullable rather than
 * omittable: `action: "create"` never carries a `memory_id`, `action:
 * "delete"` never carries `category`/`key`/`value`, but strict mode requires
 * every property in `required` with no omittable keys.
 */
final class MemoryExtractionSchema
{
    private const ACTIONS = ['create', 'update', 'delete'];

    public static function build(): StructuredOutputSchema
    {
        return new StructuredOutputSchema(
            name: 'memory_extraction',
            schema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'memories' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'action' => ['type' => 'string', 'enum' => self::ACTIONS],
                                'memory_id' => ['type' => ['integer', 'null']],
                                'category' => ['type' => ['string', 'null']],
                                'key' => ['type' => ['string', 'null']],
                                'value' => ['type' => ['string', 'null']],
                            ],
                            'required' => ['action', 'memory_id', 'category', 'key', 'value'],
                        ],
                    ],
                ],
                'required' => ['memories'],
            ],
        );
    }
}
