<?php

declare(strict_types=1);

namespace App\Service\Mcp;

/**
 * Drops planner placeholders that the tool schema does not accept.
 *
 * Optional arguments left empty, or set to a value outside their enum, are
 * removed. An object property is encoded as an object, so an empty `{}` the
 * planner wrote does not travel as `[]` after associative JSON decoding.
 */
final class McpArgumentSchema
{
    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $inputSchema
     *
     * @return array<string, mixed>
     */
    public static function clean(array $arguments, array $inputSchema): array
    {
        $properties = is_array($inputSchema['properties'] ?? null) ? $inputSchema['properties'] : [];
        if ([] === $properties) {
            return $arguments;
        }

        $required = [];
        foreach (is_array($inputSchema['required'] ?? null) ? $inputSchema['required'] : [] as $name) {
            if (is_string($name)) {
                $required[$name] = true;
            }
        }

        $cleaned = [];
        foreach ($arguments as $key => $value) {
            $schema = is_array($properties[$key] ?? null) ? $properties[$key] : null;
            if (null === $schema) {
                $cleaned[$key] = $value;
                continue;
            }

            $mustKeep = isset($required[$key]);
            if (!$mustKeep && (self::isBlank($value) || self::outsideEnum($value, $schema))) {
                continue;
            }
            if ('object' === ($schema['type'] ?? null) && is_array($value) && !self::isList($value)) {
                $value = self::asObject($value, $schema, 1);
            }
            $cleaned[$key] = $value;
        }

        return $cleaned;
    }

    private static function isBlank(mixed $value): bool
    {
        if (null === $value) {
            return true;
        }
        if (is_string($value)) {
            return '' === trim($value);
        }

        return is_array($value) && [] === $value;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function outsideEnum(mixed $value, array $schema): bool
    {
        $enum = $schema['enum'] ?? null;
        if (!is_array($enum) || [] === $enum) {
            return false;
        }

        return !in_array($value, $enum, true);
    }

    /**
     * A non-empty list is a real array. An empty array is the decoded form of `{}`.
     *
     * @param array<mixed> $value
     */
    private static function isList(array $value): bool
    {
        return [] !== $value && array_is_list($value);
    }

    /**
     * @param array<mixed>         $value
     * @param array<string, mixed> $schema
     */
    private static function asObject(array $value, array $schema, int $nestedLevels): \stdClass
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $object = new \stdClass();
        foreach ($value as $key => $child) {
            if (!is_string($key)) {
                continue;
            }
            $childSchema = is_array($properties[$key] ?? null) ? $properties[$key] : [];
            if ($nestedLevels > 0 && is_array($child) && 'object' === ($childSchema['type'] ?? null) && !self::isList($child)) {
                $object->{$key} = self::asObject($child, $childSchema, $nestedLevels - 1);
                continue;
            }
            $object->{$key} = $child;
        }

        return $object;
    }
}
