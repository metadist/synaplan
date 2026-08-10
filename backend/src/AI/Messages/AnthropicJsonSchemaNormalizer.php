<?php

declare(strict_types=1);

namespace App\AI\Messages;

/**
 * Keeps JSON Schema object fields as JSON objects across PHP's array decode.
 *
 * {@see json_decode} with associative arrays turns `{"properties":{}}` into
 * `['properties' => []]`. A later {@see json_encode} then emits
 * `"properties":[]`, which Anthropic rejects (`input_schema.properties: Input
 * should be an object`). The same corruption hits empty schema objects used as
 * `additionalProperties: {}` (Claude Code's TaskCreate `metadata` field).
 *
 * Claude Code ships several tools with these empty objects; the moment the
 * gateway mutates the body (tool injection, vision rewrite, …) and re-encodes,
 * those tools break.
 */
final class AnthropicJsonSchemaNormalizer
{
    /**
     * @param array<string, mixed> $requestBody
     *
     * @return array<string, mixed>
     */
    public function normalizeRequestBody(array $requestBody): array
    {
        if (!isset($requestBody['tools']) || !\is_array($requestBody['tools'])) {
            return $requestBody;
        }

        foreach ($requestBody['tools'] as $index => $tool) {
            if (!\is_array($tool)) {
                continue;
            }

            if (isset($tool['input_schema']) && \is_array($tool['input_schema'])) {
                $requestBody['tools'][$index]['input_schema'] = $this->normalizeSchema($tool['input_schema']);
            }

            if (isset($tool['custom']) && \is_array($tool['custom'])
                && isset($tool['custom']['input_schema']) && \is_array($tool['custom']['input_schema'])
            ) {
                $requestBody['tools'][$index]['custom']['input_schema'] = $this->normalizeSchema(
                    $tool['custom']['input_schema'],
                );
            }
        }

        return $requestBody;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    public function normalizeSchema(array $schema): array
    {
        if (\array_key_exists('properties', $schema)) {
            $schema['properties'] = $this->objectOrWalk($schema['properties']);
        }

        if (\array_key_exists('patternProperties', $schema)) {
            $schema['patternProperties'] = $this->objectOrWalk($schema['patternProperties']);
        }

        if (\array_key_exists('additionalProperties', $schema) && \is_array($schema['additionalProperties'])) {
            // `additionalProperties: {}` is a valid empty schema object; after
            // json_decode(true) it is []. Re-encoding that as a JSON array makes
            // Anthropic reject the whole tool schema as invalid draft 2020-12.
            $schema['additionalProperties'] = [] === $schema['additionalProperties']
                ? new \stdClass()
                : $this->normalizeSchema($schema['additionalProperties']);
        }

        if (\array_key_exists('propertyNames', $schema) && \is_array($schema['propertyNames'])) {
            $schema['propertyNames'] = [] === $schema['propertyNames']
                ? new \stdClass()
                : $this->normalizeSchema($schema['propertyNames']);
        }

        if (\array_key_exists('items', $schema) && \is_array($schema['items'])) {
            // Tuple form (list of schemas) vs single schema object.
            if ($this->isList($schema['items'])) {
                foreach ($schema['items'] as $i => $item) {
                    if (\is_array($item)) {
                        $schema['items'][$i] = $this->normalizeSchema($item);
                    }
                }
            } else {
                $schema['items'] = $this->normalizeSchema($schema['items']);
            }
        }

        foreach (['anyOf', 'oneOf', 'allOf', 'prefixItems'] as $combiner) {
            if (!isset($schema[$combiner]) || !\is_array($schema[$combiner])) {
                continue;
            }
            foreach ($schema[$combiner] as $i => $sub) {
                if (\is_array($sub)) {
                    $schema[$combiner][$i] = $this->normalizeSchema($sub);
                }
            }
        }

        if (isset($schema['$defs']) && \is_array($schema['$defs'])) {
            $schema['$defs'] = $this->objectOrWalk($schema['$defs']);
        }

        if (isset($schema['definitions']) && \is_array($schema['definitions'])) {
            $schema['definitions'] = $this->objectOrWalk($schema['definitions']);
        }

        return $schema;
    }

    private function objectOrWalk(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if ([] === $value) {
            return new \stdClass();
        }

        if ($this->isList($value)) {
            // A JSON Schema "properties" / "$defs" value must be a map, never a
            // list. Leave unexpected lists alone rather than inventing keys.
            return $value;
        }

        foreach ($value as $key => $child) {
            if (\is_array($child)) {
                $value[$key] = $this->normalizeSchema($child);
            }
        }

        return $value;
    }

    /**
     * @param array<mixed> $value
     */
    private function isList(array $value): bool
    {
        return array_is_list($value);
    }
}
