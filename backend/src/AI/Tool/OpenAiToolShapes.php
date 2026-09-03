<?php

declare(strict_types=1);

namespace App\AI\Tool;

/**
 * Validation, normalisation and wire-format mapping for OpenAI-shaped tools.
 *
 * The ChatProvider contract speaks Chat Completions function declarations.
 * Translators for the Anthropic Messages gateway feed Anthropic-shaped tools
 * (`name` + `input_schema`) into the same helpers so both paths share one
 * mapping table. Behaviour of the extracted translator mappings is locked by
 * OpenAiMessagesTranslatorTest and GeminiMessagesTranslatorTest.
 */
final class OpenAiToolShapes
{
    private const GEMINI_MAX_SCHEMA_DEPTH = 32;

    private function __construct()
    {
    }

    /**
     * Validate incoming Chat Completions `tools`.
     *
     * @return list<array<string, mixed>>
     *
     * @throws \InvalidArgumentException
     */
    public static function validateTools(mixed $tools): array
    {
        if (!is_array($tools)) {
            throw new \InvalidArgumentException('tools must be an array');
        }

        $out = [];
        foreach ($tools as $i => $tool) {
            if (!is_array($tool)) {
                throw new \InvalidArgumentException(sprintf('tools[%s] must be an object', (string) $i));
            }
            $type = (string) ($tool['type'] ?? '');
            if ('function' !== $type) {
                throw new \InvalidArgumentException(sprintf('tools[%s].type must be "function"', (string) $i));
            }
            $fn = $tool['function'] ?? null;
            if (!is_array($fn) || !is_string($fn['name'] ?? null) || '' === $fn['name']) {
                throw new \InvalidArgumentException(sprintf('tools[%s].function.name is required', (string) $i));
            }
            $out[] = $tool;
        }

        return $out;
    }

    /**
     * Validate incoming `tool_choice`.
     *
     * @throws \InvalidArgumentException
     */
    public static function validateToolChoice(mixed $toolChoice): mixed
    {
        if (null === $toolChoice) {
            return null;
        }
        if (is_string($toolChoice)) {
            if (!in_array($toolChoice, ['auto', 'none', 'required'], true)) {
                throw new \InvalidArgumentException('tool_choice string must be auto, none or required');
            }

            return $toolChoice;
        }
        if (!is_array($toolChoice)) {
            throw new \InvalidArgumentException('tool_choice must be a string or object');
        }
        if ('function' === ($toolChoice['type'] ?? '') && is_string($toolChoice['function']['name'] ?? null)) {
            return $toolChoice;
        }
        // Anthropic variants are accepted and normalised.
        if (in_array($toolChoice['type'] ?? '', ['auto', 'any', 'none', 'tool'], true)) {
            return self::normalizeToolChoice($toolChoice);
        }

        throw new \InvalidArgumentException('tool_choice object is malformed');
    }

    /**
     * Normalise Anthropic or OpenAI tool_choice to the Chat Completions shape.
     *
     * Anthropic `{type:auto}` → `auto`; `{type:any}` → `required`;
     * `{type:tool,name}` → `{type:function,function:{name}}`.
     * Non-array values pass through (OpenAI strings, or unknown scalars the
     * OpenAI translator historically forwarded unchanged).
     */
    public static function normalizeToolChoice(mixed $toolChoice): mixed
    {
        if (!is_array($toolChoice)) {
            return $toolChoice;
        }
        $type = (string) ($toolChoice['type'] ?? '');
        if ('auto' === $type || 'none' === $type) {
            return $type;
        }
        if ('any' === $type) {
            return 'required';
        }
        if ('tool' === $type && isset($toolChoice['name'])) {
            return [
                'type' => 'function',
                'function' => ['name' => (string) $toolChoice['name']],
            ];
        }
        if ('function' === $type) {
            return $toolChoice;
        }

        return 'auto';
    }

    /**
     * Map tools (OpenAI or Anthropic client-tool shape) to Chat Completions.
     *
     * @param list<array<string, mixed>> $tools
     *
     * @return list<array{type: 'function', function: array{name: string, description: string, parameters: mixed}}>
     */
    public static function toChatCompletionsTools(array $tools): array
    {
        $out = [];
        foreach (self::normalizeDeclarations($tools) as $decl) {
            $out[] = [
                'type' => 'function',
                'function' => [
                    'name' => $decl['name'],
                    'description' => $decl['description'],
                    'parameters' => $decl['parameters'],
                ],
            ];
        }

        return $out;
    }

    /**
     * Map tools to the OpenAI Responses API function-tool shape.
     *
     * @param list<array<string, mixed>> $tools
     *
     * @return list<array{type: 'function', name: string, description: string, parameters: mixed, strict: false}>
     */
    public static function toResponsesTools(array $tools): array
    {
        $out = [];
        foreach (self::normalizeDeclarations($tools) as $decl) {
            $out[] = [
                'type' => 'function',
                'name' => $decl['name'],
                'description' => $decl['description'],
                'parameters' => $decl['parameters'],
                'strict' => false,
            ];
        }

        return $out;
    }

    /**
     * Map tools to Anthropic Messages client-tool declarations.
     *
     * @param list<array<string, mixed>> $tools
     *
     * @return list<array{name: string, description: string, input_schema: mixed}>
     */
    public static function toAnthropicTools(array $tools): array
    {
        $out = [];
        foreach (self::normalizeDeclarations($tools) as $decl) {
            $out[] = [
                'name' => $decl['name'],
                'description' => $decl['description'],
                'input_schema' => $decl['parameters'],
            ];
        }

        return $out;
    }

    /**
     * Map tools to Gemini `functionDeclarations` (the inner list).
     *
     * Names and schema property keys are sanitised the same way the Messages
     * Gemini translator did (alphanumeric + underscore, 64 chars, depth cap).
     *
     * @param list<array<string, mixed>> $tools
     *
     * @return list<array{name: string, description: string, parametersJsonSchema: array<string, mixed>}>
     */
    public static function toGeminiDeclarations(array $tools): array
    {
        $out = [];
        foreach (self::normalizeDeclarations($tools) as $decl) {
            $schema = $decl['parameters'];
            if (!is_array($schema)) {
                $schema = ['type' => 'object', 'properties' => []];
            }
            $out[] = [
                'name' => self::sanitizeGeminiName($decl['name']),
                'description' => $decl['description'],
                'parametersJsonSchema' => self::clampSchemaDepth($schema, 0),
            ];
        }

        return $out;
    }

    /**
     * Anthropic tool_choice → Chat Completions (Messages translator helper).
     */
    public static function mapAnthropicToolChoice(mixed $toolChoice): mixed
    {
        return self::normalizeToolChoice($toolChoice);
    }

    /**
     * Chat Completions tool_choice → OpenAI Responses API.
     *
     * Strings `auto` / `none` / `required` pass through. A named function
     * becomes `{type:function, name}` (Responses does not nest `function`).
     */
    public static function toResponsesToolChoice(mixed $toolChoice): mixed
    {
        $normalized = self::normalizeToolChoice($toolChoice);
        if (null === $normalized) {
            return null;
        }
        if (is_string($normalized)) {
            return $normalized;
        }
        if (is_array($normalized) && isset($normalized['function']['name'])) {
            return [
                'type' => 'function',
                'name' => (string) $normalized['function']['name'],
            ];
        }

        return 'auto';
    }

    /**
     * Chat Completions tool_choice → Anthropic `{type: auto|any|none|tool}`.
     *
     * @return array{type: string, name?: string}|null
     */
    public static function toAnthropicToolChoice(mixed $toolChoice): ?array
    {
        $normalized = self::normalizeToolChoice($toolChoice);
        if (null === $normalized) {
            return null;
        }
        if ('none' === $normalized) {
            return ['type' => 'none'];
        }
        if ('required' === $normalized) {
            return ['type' => 'any'];
        }
        if (is_array($normalized) && isset($normalized['function']['name'])) {
            return [
                'type' => 'tool',
                'name' => (string) $normalized['function']['name'],
            ];
        }

        return ['type' => 'auto'];
    }

    /**
     * Chat Completions tool_choice → Gemini `toolConfig`.
     *
     * @return array{functionCallingConfig: array{mode: string, allowedFunctionNames?: list<string>}}|null
     */
    public static function toGeminiToolConfig(mixed $toolChoice): ?array
    {
        $normalized = self::normalizeToolChoice($toolChoice);
        if (null === $normalized) {
            return null;
        }
        if ('none' === $normalized) {
            return ['functionCallingConfig' => ['mode' => 'NONE']];
        }
        if ('required' === $normalized) {
            return ['functionCallingConfig' => ['mode' => 'ANY']];
        }
        if (is_array($normalized) && isset($normalized['function']['name'])) {
            return [
                'functionCallingConfig' => [
                    'mode' => 'ANY',
                    'allowedFunctionNames' => [self::sanitizeGeminiName((string) $normalized['function']['name'])],
                ],
            ];
        }

        return ['functionCallingConfig' => ['mode' => 'AUTO']];
    }

    /**
     * @param list<array<string, mixed>> $tools
     *
     * @return list<array{name: string, description: string, parameters: mixed}>
     */
    private static function normalizeDeclarations(array $tools): array
    {
        $out = [];
        foreach ($tools as $tool) {
            if (isset($tool['function']) && is_array($tool['function'])) {
                $fn = $tool['function'];
                $out[] = [
                    'name' => (string) ($fn['name'] ?? 'tool'),
                    'description' => (string) ($fn['description'] ?? ''),
                    'parameters' => $fn['parameters'] ?? ['type' => 'object', 'properties' => []],
                ];
                continue;
            }
            if (isset($tool['name'])) {
                $out[] = [
                    'name' => (string) $tool['name'],
                    'description' => (string) ($tool['description'] ?? ''),
                    'parameters' => $tool['input_schema'] ?? $tool['parameters'] ?? ['type' => 'object', 'properties' => []],
                ];
            }
        }

        return $out;
    }

    public static function sanitizeGeminiName(string $name): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '_', $name) ?? 'tool';
        if (strlen($sanitized) > 64) {
            $sanitized = substr($sanitized, 0, 64);
        }

        return '' !== $sanitized ? $sanitized : 'tool';
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    public static function clampSchemaDepth(array $schema, int $depth): array
    {
        if ($depth >= self::GEMINI_MAX_SCHEMA_DEPTH) {
            return ['type' => 'object'];
        }

        foreach (['properties', '$defs', 'definitions'] as $key) {
            if (!isset($schema[$key]) || !is_array($schema[$key])) {
                continue;
            }
            $out = [];
            foreach ($schema[$key] as $propName => $propSchema) {
                $safeName = self::sanitizeGeminiName((string) $propName);
                $out[$safeName] = is_array($propSchema)
                    ? self::clampSchemaDepth($propSchema, $depth + 1)
                    : $propSchema;
            }
            $schema[$key] = $out;
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = self::clampSchemaDepth($schema['items'], $depth + 1);
        }

        return $schema;
    }
}
