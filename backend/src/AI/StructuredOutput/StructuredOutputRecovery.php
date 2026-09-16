<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput;

use App\AI\Exception\StructuredOutputViolationException;

/**
 * The healing half of a {@see StructuredOutputViolationException}: turn a
 * generation the provider rejected against its schema back into a usable
 * answer, cheapest strategy first.
 *
 *  1. {@see salvage()} — no provider call. The typical rejection is the model
 *     echoing its INPUT fields next to a complete, valid answer (the sorter
 *     saw `BDATETIME`, `BTEXT`, `BFILE`, … beside every required
 *     classification key). Dropping the keys the schema forbids and
 *     re-validating locally recovers that answer verbatim; nothing is invented
 *     — a missing required field or a wrong type still fails.
 *  2. {@see repairMessages()} — one corrective turn for a single retry: the
 *     rejected output goes back as the assistant's previous answer, followed
 *     by an instruction naming the exact keys allowed. Bounded to ONE attempt
 *     by the caller ({@see \App\AI\Service\AiFacade::chat()}); a model that
 *     ignores a direct correction will not do better on a third try.
 *
 * The local check covers the JSON-Schema subset every schema in this
 * namespace uses (`type` incl. union lists, `enum`, `properties`, `required`,
 * `additionalProperties`, `items`) — the same subset
 * {@see StructuredOutputSchema} documents as the provider-portable contract.
 */
final readonly class StructuredOutputRecovery
{
    /** Response marker: the rejected generation was repaired locally, no extra provider call. */
    public const RECOVERY_SALVAGED = 'salvaged';

    /** Response marker: a corrective retry produced the answer. */
    public const RECOVERY_REPAIRED = 'repaired';

    /** Response key carrying one of the markers above; absent on a first-try success. */
    public const RESPONSE_KEY = 'structured_output_recovery';

    public function __construct(
        private JsonResponseDecoder $decoder = new JsonResponseDecoder(),
    ) {
    }

    /**
     * Re-encoded, schema-conforming JSON recovered from the rejected
     * generation, or null when the output cannot be made to conform without
     * inventing data.
     */
    public function salvage(?string $failedGeneration, StructuredOutputSchema $schema): ?string
    {
        if (null === $failedGeneration || '' === trim($failedGeneration)) {
            return null;
        }

        $decoded = $this->decoder->decode($failedGeneration);
        if (!$decoded->success) {
            return null;
        }

        $pruned = self::prune($decoded->data, $schema->schema);
        if (!self::conforms($pruned, $schema->schema)) {
            return null;
        }

        $encoded = json_encode($pruned, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return false === $encoded ? null : $encoded;
    }

    /**
     * The original conversation plus one corrective exchange, ready for a
     * single retry with the same schema attached.
     *
     * @param list<array<string, mixed>> $messages
     *
     * @return list<array<string, mixed>>
     */
    public function repairMessages(array $messages, StructuredOutputViolationException $violation, StructuredOutputSchema $schema): array
    {
        $allowedKeys = array_keys($schema->schema['properties'] ?? []);
        $keyList = [] === $allowedKeys
            ? 'the keys defined by the schema'
            : 'exactly these keys: '.implode(', ', array_map(static fn (string $key): string => '"'.$key.'"', $allowedKeys));

        $previous = $violation->getFailedGeneration() ?? '(the previous answer did not match the required JSON schema)';

        $messages[] = ['role' => 'assistant', 'content' => $previous];
        $messages[] = [
            'role' => 'user',
            'content' => 'That answer was rejected by the JSON schema validator: '.$violation->getValidationError().' '
                .'Answer again with ONLY a JSON object containing '.$keyList.'. '
                .'Do not repeat or echo any field from the message you received, do not add keys, and do not add prose.',
        ];

        return $messages;
    }

    /**
     * Drop what the schema forbids (extra keys on closed objects), recursing
     * through nested objects and array items. Never adds or rewrites a value.
     *
     * @param array<string, mixed> $schema
     */
    private static function prune(mixed $value, array $schema): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $properties = $schema['properties'] ?? null;
        if (is_array($properties) && self::isObjectLike($value)) {
            if (false === ($schema['additionalProperties'] ?? null)) {
                $value = array_intersect_key($value, $properties);
            }
            foreach ($value as $key => $item) {
                if (isset($properties[$key]) && is_array($properties[$key])) {
                    $value[$key] = self::prune($item, $properties[$key]);
                }
            }

            return $value;
        }

        $items = $schema['items'] ?? null;
        if (is_array($items) && array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::prune($item, $items), $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function conforms(mixed $value, array $schema): bool
    {
        if (array_key_exists('enum', $schema) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            return false;
        }

        $type = $schema['type'] ?? null;
        if (null !== $type && !self::matchesType($value, $type)) {
            return false;
        }

        if (is_array($value) && self::isObjectLike($value) && isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['required'] ?? [] as $required) {
                if (!array_key_exists($required, $value)) {
                    return false;
                }
            }
            if (false === ($schema['additionalProperties'] ?? null) && [] !== array_diff_key($value, $schema['properties'])) {
                return false;
            }
            foreach ($value as $key => $item) {
                if (isset($schema['properties'][$key]) && is_array($schema['properties'][$key])
                    && !self::conforms($item, $schema['properties'][$key])) {
                    return false;
                }
            }
        }

        if (is_array($value) && array_is_list($value) && isset($schema['items']) && is_array($schema['items'])) {
            foreach ($value as $item) {
                if (!self::conforms($item, $schema['items'])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * A type name outside the seven JSON Schema primitives cannot be checked,
     * and an unchecked value is not a salvaged one: it fails closed, so the
     * caller falls through to the corrective retry instead of trusting output
     * nobody validated.
     *
     * @param string|list<string> $type
     */
    private static function matchesType(mixed $value, string|array $type): bool
    {
        foreach ((array) $type as $candidate) {
            $matches = match ($candidate) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'null' => null === $value,
                'object' => is_array($value) && self::isObjectLike($value),
                'array' => is_array($value) && array_is_list($value),
                default => false,
            };
            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * An empty array decodes identically for `{}` and `[]`; treat it as an
     * object so an empty object result is not rejected.
     *
     * @param array<mixed> $value
     */
    private static function isObjectLike(array $value): bool
    {
        return [] === $value || !array_is_list($value);
    }
}
