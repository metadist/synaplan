<?php

declare(strict_types=1);

namespace App\AI\Provider;

/**
 * Attaches the finish the rest of the pipeline reads.
 *
 * A turn the model cut off (`max_tokens` / `MAX_TOKENS`) becomes
 * `finish_reason` `length`, which is how a translation can refuse a
 * partial document. Real tool calls stay `tool_calls`.
 */
final class ChatStopReason
{
    /**
     * @param array<string, mixed> $result
     * @param list<mixed>          $toolCalls
     *
     * @return array<string, mixed>
     */
    public static function apply(array $result, array $toolCalls, mixed $stopReason): array
    {
        if ([] !== $toolCalls) {
            $result['tool_calls'] = $toolCalls;
            $result['finish_reason'] = 'tool_calls';

            return $result;
        }

        if (\in_array($stopReason, ['max_tokens', 'MAX_TOKENS'], true)) {
            $result['finish_reason'] = 'length';
        } elseif ('tool_use' === $stopReason) {
            $result['finish_reason'] = 'tool_calls';
        }

        return $result;
    }
}
