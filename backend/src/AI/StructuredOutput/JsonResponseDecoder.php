<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput;

/**
 * The one JSON decoder, consolidating the tolerance of the six independently
 * grown parsers documented in
 * {@see \App\Tests\Characterization\JsonParserGoldenCorpusTest}:
 *
 *   - fence-stripping (all six)
 *   - outermost `{…}`/`[…]` extraction from surrounding prose
 *     (TaskPlanner::decodeJson, FeedbackContradictionService::extractJson,
 *     FileGenerationEnvelope::extract)
 *   - brace/bracket-balance repair for a response cut off by a token budget
 *     (UserMemoryController::repairJson)
 *
 * This is the acceptance bar from phase 0b: recover at least everything the
 * golden corpus marks RECOVERED for any of the six parsers it documents, so
 * migrating a call site to this decoder is never a fault-tolerance
 * regression. It intentionally does NOT invent new tolerance beyond that
 * (e.g. smart-quote repair, mid-string truncation) — those never worked
 * anywhere in this codebase and adding them here would be new, unreviewed
 * risk sitting inside a "just consolidate" change.
 */
final class JsonResponseDecoder
{
    public function decode(string $raw): DecodeResult
    {
        $text = trim($raw);
        if ('' === $text) {
            return DecodeResult::fail('empty_response');
        }

        $text = self::stripFences($text);
        if ('' === $text) {
            return DecodeResult::fail('empty_after_fence_strip');
        }

        $direct = self::tryDecode($text);
        if (null !== $direct) {
            return DecodeResult::ok($direct);
        }

        // Prose-embedded object/array: grab the outermost balanced {…} or […]
        // even when the model prefixed or suffixed it with conversational text.
        $embedded = self::extractEmbedded($text);
        if (null !== $embedded && $embedded !== $text) {
            $decoded = self::tryDecode($embedded);
            if (null !== $decoded) {
                return DecodeResult::ok($decoded);
            }

            $repairedEmbedded = self::repair($embedded);
            $decoded = self::tryDecode($repairedEmbedded);
            if (null !== $decoded) {
                return DecodeResult::ok($decoded);
            }
        }

        // Truncated response (completion budget ran out mid-object): try
        // balancing braces/brackets on the whole text as a last resort.
        $repaired = self::repair($text);
        $decoded = self::tryDecode($repaired);
        if (null !== $decoded) {
            return DecodeResult::ok($decoded);
        }

        return DecodeResult::fail('invalid_json');
    }

    private static function stripFences(string $text): string
    {
        if (!str_starts_with($text, '```')) {
            return $text;
        }

        $text = (string) preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = (string) preg_replace('/\s*```\s*$/', '', $text);

        return trim($text);
    }

    /**
     * Extract the outermost `{…}` or `[…]` from a string that may carry
     * conversational prose before or after it. Mirrors
     * TaskPlanner::decodeJson()'s strpos/strrpos approach but also accepts a
     * top-level array, which some schemas (e.g. a list of contradictions)
     * legitimately return.
     */
    private static function extractEmbedded(string $text): ?string
    {
        if (str_starts_with($text, '{') || str_starts_with($text, '[')) {
            return $text;
        }

        $bestStart = null;
        $bestEnd = null;

        foreach (['{' => '}', '[' => ']'] as $open => $close) {
            $start = strpos($text, $open);
            $end = strrpos($text, $close);
            if (false === $start || false === $end || $end <= $start) {
                continue;
            }

            if (null === $bestStart || $start < $bestStart) {
                $bestStart = $start;
                $bestEnd = $end;
            }
        }

        if (null === $bestStart || null === $bestEnd) {
            return null;
        }

        return substr($text, $bestStart, $bestEnd - $bestStart + 1);
    }

    /**
     * Best-effort structural repair for a response truncated by a token
     * budget: balance an excess of closing braces/brackets, or append the
     * closing characters missing from the end. Ported from
     * UserMemoryController::repairJson(). Cannot repair a truncation that cut
     * a string literal or a nested nesting order mid-way — those remain
     * `invalid_json`, exactly as they do in every legacy parser today.
     */
    private static function repair(string $json): string
    {
        $json = trim($json);

        $openBraces = substr_count($json, '{');
        $closeBraces = substr_count($json, '}');
        $openBrackets = substr_count($json, '[');
        $closeBrackets = substr_count($json, ']');

        while ($closeBraces > $openBraces && str_contains($json, '}}')) {
            $json = (string) preg_replace('/\}\}(\]|\})/', '}$1', $json, 1);
            $closeBraces = substr_count($json, '}');
        }

        while ($closeBrackets > $openBrackets && str_contains($json, ']]')) {
            $json = (string) preg_replace('/\]\]/', ']', $json, 1);
            $closeBrackets = substr_count($json, ']');
        }

        while ($openBraces > $closeBraces) {
            $json .= '}';
            ++$closeBraces;
        }

        while ($openBrackets > $closeBrackets) {
            $json .= ']';
            ++$closeBrackets;
        }

        return $json;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function tryDecode(string $text): ?array
    {
        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
