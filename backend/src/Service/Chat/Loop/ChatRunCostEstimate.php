<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

/**
 * What a model call of a run will cost, in what the person is billed
 * (micro-dollars). An estimate, so the run can hold back enough for its
 * wrap-up call (03 §1.3).
 *
 * The model's price ({@see ChatRunRates}) gives it: what the call sends
 * as input plus a full answer.
 */
final class ChatRunCostEstimate
{
    /** Characters a unit sends: the base prompt and the transcript. */
    public static function size(ChatRunState $state): int
    {
        return max(1, mb_strlen($state->basePrompt) + self::chars($state->messages));
    }

    /**
     * Characters of a list of chat messages, tool calls included.
     *
     * @param list<array<string, mixed>> $messages
     */
    public static function chars(array $messages): int
    {
        $chars = 0;
        foreach ($messages as $message) {
            $content = $message['content'] ?? null;
            $chars += \is_string($content) ? mb_strlen($content) : 0;
            if (isset($message['tool_calls'])) {
                $chars += mb_strlen((string) json_encode($message['tool_calls'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE));
            }
        }

        return $chars;
    }

    /**
     * The wrap-up call of the run: the transcript as it is now plus a full
     * answer at the model's price, never less than the dearest call so far.
     * Without a price, the last call scaled to the transcript now.
     */
    public static function wrapUp(ChatRunState $state): int
    {
        if (null !== $state->rates) {
            return max($state->dearestMicro, $state->rates->call(self::size($state)));
        }
        if ($state->lastCallMicro <= 0 || $state->lastCallChars <= 0) {
            return $state->dearestMicro;
        }

        return max($state->dearestMicro, (int) ceil($state->lastCallMicro * (self::size($state) / $state->lastCallChars)));
    }
}
