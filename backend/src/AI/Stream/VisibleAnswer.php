<?php

declare(strict_types=1);

namespace App\AI\Stream;

/**
 * User-visible answer text after a thinking model has finished.
 *
 * Qwen3 and similar models, often reached through an OpenAI-compatible
 * gateway such as Ollama, spend the completion budget on a `<think>` block
 * first. When that budget runs out the JSON plan or the reply is cut off and
 * the only thing left is reasoning. Callers must not persist that as a
 * finished answer (#2264).
 */
final class VisibleAnswer
{
    private function __construct()
    {
    }

    public static function withoutReasoning(string $text): string
    {
        $text = preg_replace('/<think\b[^>]*>[\s\S]*?<\/think>/i', '', $text) ?? $text;
        $text = preg_replace('/<think\b[^>]*>[\s\S]*$/i', '', $text) ?? $text;

        return trim($text);
    }

    public static function containsReasoning(string $text): bool
    {
        return 1 === preg_match('/<think\b/i', $text);
    }

    /**
     * An unclosed `<think>` means the completion ended inside the scratchpad,
     * which is what a token limit looks like when the gateway omits
     * finish_reason.
     */
    public static function reasoningSwallowedTheAnswer(string $raw): bool
    {
        if ('' !== self::withoutReasoning($raw) || !self::containsReasoning($raw)) {
            return false;
        }

        return 1 !== preg_match('/<\/think>/i', $raw);
    }

    public static function outputWasCut(?string $finishReason): bool
    {
        return in_array($finishReason, ['length', 'max_tokens'], true);
    }

    /**
     * A `{` / `[` payload that never became JSON — the shape a token limit
     * leaves when it cuts a plan or a tool call mid-object.
     */
    public static function isCutJson(string $text): bool
    {
        $trim = ltrim($text);
        if (!str_starts_with($trim, '{') && !str_starts_with($trim, '[')) {
            return false;
        }

        try {
            json_decode($trim, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // Balanced-but-invalid JSON (a trailing comma) is a bad answer, not
            // evidence the token budget ran out. Only an unclosed object is.
            $open = substr_count($trim, '{') + substr_count($trim, '[');
            $close = substr_count($trim, '}') + substr_count($trim, ']');

            return $open > $close;
        }

        return false;
    }

    /**
     * Nothing a person can read: reasoning only, or a JSON blob the token
     * limit cut in half.
     */
    public static function isUnusable(string $raw, ?string $finishReason): bool
    {
        $visible = self::withoutReasoning($raw);
        if ('' === $visible) {
            return self::outputWasCut($finishReason) || self::containsReasoning($raw);
        }

        return self::isCutJson($visible);
    }

    /**
     * The unusable text was produced because the model ran out of room, as
     * opposed to a clean stop that simply had nothing to say.
     */
    public static function failedBecauseOutputWasCut(string $raw, ?string $finishReason): bool
    {
        if (!self::isUnusable($raw, $finishReason)) {
            return false;
        }

        return self::outputWasCut($finishReason)
            || self::reasoningSwallowedTheAnswer($raw)
            || self::isCutJson(self::withoutReasoning($raw));
    }

    /**
     * Local models that hide the answer behind a thinking channel and will
     * burn a small max_tokens budget before emitting JSON (#2264).
     */
    public static function modelHidesAnswerBehindThinking(string $model): bool
    {
        return 1 === preg_match('/qwen3|qwq|deepseek-r1/i', $model);
    }
}
