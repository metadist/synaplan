<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution;

use App\Service\Multitask\Plan\TaskNode;

/**
 * Sanitized input and a capped output preview for one task step.
 *
 * Stored with the message so a person can open the step after a reload.
 * Credentials and Authorization values are masked before anything is saved.
 * The body is not written to the info log.
 */
final class StepTrace
{
    public const CAP = 8192;

    private const SENSITIVE_KEY = '/token|secret|password|passwd|authorization|credential|api[_-]?key/i';

    /**
     * @param array<string, mixed> $resolvedInputs values the step actually received
     *
     * @return array{input: string, output: string, outputTruncated: bool, durationMs: int}
     */
    public static function capture(TaskNode $node, NodeResult $result, int $durationMs, array $resolvedInputs = []): array
    {
        $output = '';
        $trace = $result->metadata['trace_output'] ?? null;
        if (is_string($trace) && '' !== trim($trace)) {
            $output = $trace;
        } elseif (is_string($result->text) && '' !== trim($result->text)) {
            $output = $result->text;
        } elseif (is_string($result->error) && '' !== trim($result->error)) {
            $output = $result->error;
        }

        $truncated = mb_strlen($output) > self::CAP;
        if ($truncated) {
            $output = mb_substr($output, 0, self::CAP);
        }

        $recorded = $result->metadata['trace_input'] ?? null;
        $input = is_array($recorded) && [] !== $recorded
            ? $recorded
            : ([] !== $resolvedInputs ? $resolvedInputs : $node->params);

        return [
            'input' => self::encode(self::mask($input)),
            'output' => $output,
            'outputTruncated' => $truncated,
            'durationMs' => max(0, $durationMs),
        ];
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return array<string, mixed>
     */
    private static function mask(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            $name = (string) $key;
            if (1 === preg_match(self::SENSITIVE_KEY, $name)) {
                $out[$name] = '[redacted]';
                continue;
            }
            if (is_array($item)) {
                $out[$name] = self::mask($item);
                continue;
            }
            if (is_string($item) && mb_strlen($item) > self::CAP) {
                $out[$name] = mb_substr($item, 0, self::CAP);
                continue;
            }
            $out[$name] = $item;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function encode(array $value): string
    {
        if ([] === $value) {
            return '';
        }
        $json = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) {
            return '';
        }
        if (mb_strlen($json) > self::CAP) {
            return mb_substr($json, 0, self::CAP);
        }

        return $json;
    }
}
