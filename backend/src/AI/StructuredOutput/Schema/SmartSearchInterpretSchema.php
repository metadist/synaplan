<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\StructuredOutputSchema;
use App\Service\SmartSearch\Interpret\InterpretResult;

/**
 * JSON schema for {@see \App\Service\SmartSearch\Interpret\SearchInterpreter}:
 * the intent, the chosen candidate ids and one sentence for the user.
 */
final class SmartSearchInterpretSchema
{
    public static function build(): StructuredOutputSchema
    {
        return new StructuredOutputSchema(
            name: 'smart_search_interpret',
            schema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'intent' => ['type' => 'string', 'enum' => InterpretResult::INTENTS],
                    'targetIds' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'answer' => ['type' => ['string', 'null']],
                ],
                'required' => ['intent', 'targetIds', 'answer'],
            ],
        );
    }
}
