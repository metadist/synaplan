<?php

declare(strict_types=1);

namespace App\AI\Messages;

/**
 * How much to search, told to the model once per gateway turn in which
 * Synaplan runs the web search itself. The ceiling of rounds is the
 * budget; this paragraph is the judgement inside it: one fact, one search;
 * a comparison or a procedure, one search per part; never for greetings,
 * code, writing, translation or what the conversation already answered.
 */
final class GatewayEffortRules
{
    public const PARAGRAPH = 'Scale your searches to the question. One fact: one search, then answer. '
        .'A comparison or a procedure with several parts: one search per part, read the pages that matter, '
        .'then answer with every part and cite the pages. '
        .'Never search for greetings, code, writing, translation or questions this conversation already answers.';

    /**
     * Appends the paragraph as the last system block. Idempotent: a body that
     * already carries it is returned as is, so a loop that rebuilds the body
     * per upstream call never says it twice.
     *
     * @param array<string, mixed> $requestBody
     *
     * @return array<string, mixed>
     */
    public static function apply(array $requestBody): array
    {
        $system = $requestBody['system'] ?? null;
        $block = ['type' => 'text', 'text' => self::PARAGRAPH];

        if (null === $system || '' === $system) {
            $requestBody['system'] = [$block];

            return $requestBody;
        }
        if (\is_string($system)) {
            $requestBody['system'] = [['type' => 'text', 'text' => $system], $block];

            return $requestBody;
        }
        if (!\is_array($system)) {
            return $requestBody;
        }
        foreach ($system as $existing) {
            if (\is_array($existing) && self::PARAGRAPH === ($existing['text'] ?? null)) {
                return $requestBody;
            }
        }
        $system[] = $block;
        $requestBody['system'] = $system;

        return $requestBody;
    }
}
