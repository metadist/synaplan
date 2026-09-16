<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\StructuredOutputSchema;

/**
 * JSON schema for {@see \App\Controller\UserMemoryController}'s
 * "Parse into memory" endpoint.
 *
 * Replaces the dead `'json_mode' => true` option, which no provider ever
 * read — the endpoint has always relied purely on prompt instructions plus
 * a tolerant, three-format fallback parser
 * ({@see \App\Controller\UserMemoryController::parseAiResponse()}).
 *
 * The schema mirrors {@see MemoryExtractionSchema} in shape (a `create` /
 * `update` / `delete` action list wrapped under a root object, since a bare
 * top-level array is rejected by OpenAI-dialect structured output and
 * Anthropic tool-forcing), but keeps the controller's own field names
 * (`existingId`, nested `memory` object, `reason`) so no parsing change is
 * required: `parseAiResponse()`'s "Format 1: {"actions": [...]}" branch
 * handles exactly this envelope.
 *
 * `existingId`, `memory` and `reason` are modelled as nullable rather than
 * omittable: `action: "create"` never carries an `existingId`, `action:
 * "delete"` never carries a `memory` payload, but strict mode requires
 * every property in `required` with no omittable keys.
 */
final class UserMemoryActionSchema
{
    private const ACTIONS = ['create', 'update', 'delete'];

    public static function build(): StructuredOutputSchema
    {
        return new StructuredOutputSchema(
            name: 'user_memory_actions',
            schema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'actions' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'action' => ['type' => 'string', 'enum' => self::ACTIONS],
                                'existingId' => ['type' => ['integer', 'null']],
                                'memory' => [
                                    'type' => ['object', 'null'],
                                    'additionalProperties' => false,
                                    'properties' => [
                                        'category' => ['type' => 'string'],
                                        'key' => ['type' => 'string'],
                                        'value' => ['type' => 'string'],
                                    ],
                                    'required' => ['category', 'key', 'value'],
                                ],
                                'reason' => ['type' => ['string', 'null']],
                            ],
                            'required' => ['action', 'existingId', 'memory', 'reason'],
                        ],
                    ],
                ],
                'required' => ['actions'],
            ],
        );
    }
}
