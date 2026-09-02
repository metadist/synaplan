<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\StructuredOutputSchema;

/**
 * JSON schema for {@see \App\Service\Message\MessageSorter}'s classification
 * call — the single most consequential decision in the whole pipeline.
 *
 * Not a constant: `BTOPIC` is built PER CALL from the same dynamic topic list
 * that already feeds the prompt's `[KEYLIST]` placeholder
 * ({@see \App\Repository\PromptRepository::getAllTopics()}), because a
 * user's own custom topics extend the valid set. `BLANG` is likewise built
 * from the sorter's fixed language list rather than hard-coded here, so the
 * two never drift apart.
 *
 * Optional fields (`BMEDIA`, `BINPUTMODE`, `BDURATION`, `BRESOLUTION`,
 * `BMULTI`) are modelled as nullable types (`["string", "null"]`) rather than
 * omittable keys: OpenAI/Groq strict mode requires `additionalProperties:
 * false` and every property listed in `required`, which is incompatible with
 * "leave the key out when not applicable". A provider without schema support
 * still receives an omitted key from its prose-only response, which
 * {@see \App\Service\Message\MessageSorter::parseResponse()} already treats
 * as "no vote" via `array_key_exists()` / `isset()` checks.
 */
final class SortClassificationSchema
{
    private const MEDIA_TYPES = ['image', 'video', 'audio'];

    private const INPUT_MODES = ['text_only', 'reference_images'];

    private const VIDEO_RESOLUTIONS = ['720p', '1080p', '4K'];

    /**
     * @param list<string> $topics    Valid BTOPIC values for this user (system topics + their own custom ones)
     * @param list<string> $languages Valid BLANG codes ({@see \App\Service\Message\MessageSorter::SUPPORTED_LANGUAGES})
     */
    public static function build(array $topics, array $languages): StructuredOutputSchema
    {
        $topicEnum = [] !== $topics ? $topics : null;
        $languageEnum = [] !== $languages ? $languages : null;

        return new StructuredOutputSchema(
            name: 'sort_classification',
            schema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'BTOPIC' => null !== $topicEnum
                        ? ['type' => 'string', 'enum' => $topicEnum]
                        : ['type' => 'string'],
                    'BLANG' => null !== $languageEnum
                        ? ['type' => 'string', 'enum' => $languageEnum]
                        : ['type' => 'string'],
                    'BWEBSEARCH' => ['type' => 'boolean'],
                    'BMULTI' => ['type' => ['boolean', 'null']],
                    'BMEDIA' => ['type' => ['string', 'null'], 'enum' => [...self::MEDIA_TYPES, null]],
                    'BINPUTMODE' => ['type' => ['string', 'null'], 'enum' => [...self::INPUT_MODES, null]],
                    'BDURATION' => ['type' => ['integer', 'null']],
                    'BRESOLUTION' => ['type' => ['string', 'null'], 'enum' => [...self::VIDEO_RESOLUTIONS, null]],
                ],
                'required' => ['BTOPIC', 'BLANG', 'BWEBSEARCH', 'BMULTI', 'BMEDIA', 'BINPUTMODE', 'BDURATION', 'BRESOLUTION'],
            ],
        );
    }
}
