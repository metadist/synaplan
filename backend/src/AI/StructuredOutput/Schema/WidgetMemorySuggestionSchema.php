<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\StructuredOutputSchema;

/**
 * JSON schema for {@see \App\Service\WidgetSetupService::suggestMemoriesForWidget()}.
 *
 * The natural output is a JSON ARRAY, wrapped under a `suggestions` property
 * because structured output requires an `object` root. No parsing change is
 * needed: the existing `preg_match('/\[.*\]/s', ...)` in
 * `suggestMemoriesForWidget()` already extracts the innermost `[...]`
 * whether wrapped or bare, since the wrapping object contributes no other
 * square brackets.
 *
 * `meta` is deliberately left as an open, unconstrained object — it is `{}`
 * for a plain text response and `{"url": "..."}` for a link, so there is no
 * single fixed property set. This is why the schema opts out of strict mode,
 * mirroring {@see TaskPlanSchema}'s reasoning for its own open `inputs`/`params`.
 */
final class WidgetMemorySuggestionSchema
{
    private const RESPONSE_TYPES = ['text', 'link', 'list'];

    public static function build(): StructuredOutputSchema
    {
        return new StructuredOutputSchema(
            name: 'widget_memory_suggestions',
            schema: [
                'type' => 'object',
                'properties' => [
                    'suggestions' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'widgetField' => ['type' => 'string'],
                                'responseType' => ['type' => 'string', 'enum' => self::RESPONSE_TYPES],
                                'meta' => ['type' => 'object'],
                            ],
                            'required' => ['id', 'widgetField', 'responseType'],
                        ],
                    ],
                ],
                'required' => ['suggestions'],
            ],
            strict: false,
        );
    }
}
