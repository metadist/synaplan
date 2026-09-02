<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\StructuredOutputSchema;

/**
 * JSON schema for {@see \App\Service\Digest\MessageDigestService}'s batch
 * digest call.
 *
 * As with {@see MemoryExtractionSchema}, the natural output is a JSON ARRAY
 * of proposals; it is wrapped under a `digests` property because structured
 * output requires an `object` root. {@see
 * \App\Service\Digest\MessageDigestService::parseDigestsFromResponse()}
 * needs no change — its regex already extracts the innermost `[...]`
 * whether wrapped or bare.
 */
final class MessageDigestSchema
{
    public static function build(): StructuredOutputSchema
    {
        return new StructuredOutputSchema(
            name: 'message_digest',
            schema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'digests' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'title' => ['type' => 'string'],
                                'message_id' => ['type' => 'integer'],
                            ],
                            'required' => ['title', 'message_id'],
                        ],
                    ],
                ],
                'required' => ['digests'],
            ],
        );
    }
}
