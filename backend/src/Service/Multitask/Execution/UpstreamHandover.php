<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution;

use App\Service\Multitask\Plan\TaskNode;

/**
 * Guarantees that a text node sees the output of the steps it depends on.
 *
 * The planner is supposed to splice upstream output into the answering node's
 * prompt (`"… based on:\n$n1.text"`). When it forgets, references the wrong
 * field, or parks the data under another input key (`"context": "$n1.text"`),
 * the answering model receives only the instruction, truthfully reports that
 * no data was provided — and the tool call that DID succeed is wasted. This
 * was reported against connected MCP systems ("tool returned a result, the
 * answer says no results were provided").
 *
 * {@see missing()} returns the text of every successful dependency that the
 * resolved prompt does not already carry verbatim; {@see render()} formats
 * those as a clearly labelled block the runner appends to the user message.
 * A correctly wired plan yields nothing — the data is already in the prompt —
 * so well-formed plans are untouched.
 */
final class UpstreamHandover
{
    /** Per-step cap, mirrors the data-node output caps (url_fetch / mcp_fetch). */
    private const MAX_CHARS_PER_STEP = 12000;

    /**
     * Upstream text the prompt does not contain yet, in `depends_on` order,
     * plus any extra `inputs` entry whose raw value references a node (the
     * "data under another key" shape).
     *
     * @param array<string, mixed> $resolvedInputs output of NodeContext::resolveInputs()
     *
     * @return array<string, string> label => text
     */
    public static function missing(TaskNode $node, NodeContext $context, string $resolvedText, array $resolvedInputs = []): array
    {
        $missing = [];

        foreach ($node->dependsOn as $dep) {
            $result = $context->getResult($dep);
            if (null === $result) {
                continue;
            }
            if ($result->isReportableFailure()) {
                $error = trim((string) $result->error);
                // `$nX.text` on a failed step is empty, so the verbatim check
                // cannot see it. Skip only when the prompt already quotes the
                // error itself (the chat step explained it; compose copies that).
                if ('' === $error || str_contains($resolvedText, $error)) {
                    continue;
                }
                $missing[self::label($dep, $result).' · FAILED'] = $error;
                continue;
            }
            if (!$result->isSuccessful()) {
                continue;
            }
            $text = trim((string) $result->text);
            if ('' === $text || str_contains($resolvedText, $text)) {
                continue;
            }
            $missing[self::label($dep, $result)] = $text;
        }

        foreach ($node->inputs as $key => $raw) {
            if ('text' === $key || 'prompt' === $key || !self::rawReferencesANode($raw)) {
                continue;
            }
            $value = $resolvedInputs[$key] ?? null;
            $text = self::flatten($value);
            if ('' === $text || str_contains($resolvedText, $text)) {
                continue;
            }
            foreach ($missing as $already) {
                if (str_contains($text, $already)) {
                    // Same data, already listed under its node — do not repeat it.
                    continue 2;
                }
            }
            $missing[(string) $key] = $text;
        }

        return $missing;
    }

    /**
     * @param array<string, string> $missing
     */
    public static function render(array $missing): string
    {
        if ([] === $missing) {
            return '';
        }

        $blocks = [];
        $mentionsFailure = false;
        foreach ($missing as $label => $text) {
            if (str_ends_with((string) $label, ' · FAILED')) {
                $mentionsFailure = true;
            }
            $blocks[] = '['.$label."]\n".mb_substr($text, 0, self::MAX_CHARS_PER_STEP);
        }

        $failureLine = $mentionsFailure
            ? "\nA step marked FAILED did run. Say what failed and why, in the user's language. Do not say that the connection or the data source does not exist."
            : '';

        return "\n\n---\nData returned by the previous steps of this request."
            ."\nUse it as the source of truth for your answer and never claim it was not provided."
            .$failureLine
            ."\n\n".implode("\n\n", $blocks);
    }

    /**
     * Node ids whose output had to be appended (for the runner's log line).
     *
     * @param array<string, string> $missing
     *
     * @return list<string>
     */
    public static function labels(array $missing): array
    {
        return array_map('strval', array_keys($missing));
    }

    private static function label(string $nodeId, NodeResult $result): string
    {
        $query = $result->metadata['query'] ?? null;
        if (is_string($query) && '' !== trim($query)) {
            return $nodeId.' · '.trim($query);
        }

        return $nodeId;
    }

    private static function rawReferencesANode(mixed $raw): bool
    {
        if (is_array($raw)) {
            foreach ($raw as $item) {
                if (self::rawReferencesANode($item)) {
                    return true;
                }
            }

            return false;
        }

        return is_string($raw) && 1 === preg_match('/\$\{?(?!message\.)[A-Za-z0-9_]+\.[A-Za-z]+\}?/', $raw);
    }

    private static function flatten(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                $part = self::flatten($item);
                if ('' !== $part) {
                    $parts[] = $part;
                }
            }

            return implode("\n\n", $parts);
        }

        return '';
    }
}
