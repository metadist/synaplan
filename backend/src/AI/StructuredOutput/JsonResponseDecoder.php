<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput;

/**
 * The one JSON decoder for turning a raw model reply into structured data,
 * consolidating the tolerance of the independently grown parsers documented
 * in {@see \App\Tests\Characterization\JsonParserGoldenCorpusTest}:
 *
 *   - fence-stripping (every former parser had its own variant)
 *   - outermost `{…}`/`[…]` extraction from surrounding prose
 *   - brace/bracket-balance repair for a response cut off by a token budget
 *
 * Every generic call site delegates here: {@see \App\Service\Message\MessageSorter},
 * {@see \App\Service\Multitask\TaskPlanner},
 * {@see \App\Service\Message\MediaPromptExtractor},
 * {@see \App\Service\FeedbackContradictionService},
 * {@see \App\Service\Digest\MessageDigestService},
 * {@see \App\Service\MemoryExtractionService} and
 * {@see \App\Controller\UserMemoryController}. The one deliberate holdout is
 * {@see \App\Service\File\FileGenerationEnvelope}, whose key-anchored,
 * string-literal-aware brace walk is strictly more precise for the
 * officemaker envelope than a generic outermost-span search.
 *
 * The acceptance bar is the golden corpus: recover at least everything it
 * marks RECOVERED for any former parser, so no migration is a fault-tolerance
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
        foreach (self::extractEmbeddedCandidates($text) as $candidate) {
            if ($candidate === $text) {
                continue;
            }

            $decoded = self::tryDecode($candidate);
            if (null !== $decoded) {
                return DecodeResult::ok($decoded);
            }

            $decoded = self::tryDecode(self::repair($candidate));
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
     * Extract the outermost `{…}` and `[…]` spans from a string that may carry
     * conversational prose before or after them. Mirrors
     * TaskPlanner::decodeJson()'s strpos/strrpos approach but also accepts a
     * top-level array, which some schemas (e.g. a list of contradictions)
     * legitimately return.
     *
     * Both bracket kinds are returned, earliest-starting first, because prose
     * routinely contains a square-bracket span that is not the payload ("see
     * [appendix] for details: {…}"). Committing to whichever bracket appears
     * first would discard the real object; the caller instead tries each until
     * one decodes.
     *
     * @return list<string>
     */
    private static function extractEmbeddedCandidates(string $text): array
    {
        if (str_starts_with($text, '{') || str_starts_with($text, '[')) {
            return [$text];
        }

        $candidates = [];

        foreach (['{' => '}', '[' => ']'] as $open => $close) {
            $start = strpos($text, $open);
            $end = strrpos($text, $close);
            if (false === $start || false === $end || $end <= $start) {
                continue;
            }

            $candidates[$start] = substr($text, $start, $end - $start + 1);
        }

        ksort($candidates);

        return array_values($candidates);
    }

    /**
     * Best-effort structural repair for a response truncated by a token
     * budget: balance an excess of closing braces/brackets, or append the
     * closing characters missing from the end. Cannot repair a truncation
     * that cut a string literal or a key mid-way — those remain
     * `invalid_json`, exactly as they did in every former parser.
     */
    private static function repair(string $json): string
    {
        $json = trim($json);

        $openBraces = substr_count($json, '{');
        $closeBraces = substr_count($json, '}');
        $openBrackets = substr_count($json, '[');
        $closeBrackets = substr_count($json, ']');

        // The pattern only matches a `}}` that is FOLLOWED by `]` or `}`, so a
        // trailing `}}` (e.g. `{"a":1}}`) leaves the count untouched while the
        // loop condition stays true. Stop as soon as an iteration makes no
        // progress — otherwise a model response spins the request forever.
        while ($closeBraces > $openBraces && str_contains($json, '}}')) {
            $json = (string) preg_replace('/\}\}(\]|\})/', '}$1', $json, 1);
            $remaining = substr_count($json, '}');
            if ($remaining === $closeBraces) {
                break;
            }
            $closeBraces = $remaining;
        }

        while ($closeBrackets > $openBrackets && str_contains($json, ']]')) {
            $json = (string) preg_replace('/\]\]/', ']', $json, 1);
            $remaining = substr_count($json, ']');
            if ($remaining === $closeBrackets) {
                break;
            }
            $closeBrackets = $remaining;
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
