<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution;

/**
 * Reads one named field (`$n1.page_id`, `$n1.url`) out of a node's JSON
 * output. A search result list answers with its first row, so a write step
 * can target the item an earlier search found.
 */
final class StructuredField
{
    /** Keys a JSON payload uses for its list of rows. */
    private const LIST_KEYS = ['results', 'items', 'pages', 'hits', 'data', 'records'];

    /** Matches a pure reference that nothing resolved, e.g. `$n1.page_id`. */
    public const UNRESOLVED_PATTERN = '/^\$\{?[A-Za-z0-9_]+\.[A-Za-z_][A-Za-z0-9_]*\}?$/';

    public static function from(string $text, string $field): ?string
    {
        $trimmed = trim($text);
        if ('' === $trimmed || ('{' !== $trimmed[0] && '[' !== $trimmed[0])) {
            return null;
        }

        $payload = json_decode($trimmed, true);
        if (!is_array($payload)) {
            return null;
        }

        return self::lookup($payload, $field) ?? self::lookup(self::firstRow($payload), $field);
    }

    /**
     * Whether any argument value is still a bare `$nX.field` placeholder.
     *
     * @param array<mixed> $arguments
     */
    public static function hasUnresolved(array $arguments): bool
    {
        foreach ($arguments as $value) {
            if (is_array($value) && self::hasUnresolved($value)) {
                return true;
            }
            if (is_string($value) && 1 === preg_match(self::UNRESOLVED_PATTERN, trim($value))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed>|null $row
     */
    private static function lookup(?array $row, string $field): ?string
    {
        if (null === $row || array_is_list($row)) {
            return null;
        }

        foreach (self::candidates($field) as $key) {
            $value = $row[$key] ?? null;
            if (is_string($value) && '' !== trim($value)) {
                return trim($value);
            }
            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * `page_id` / `pageId` fall back to `id`, `page_url` to `url`.
     *
     * @return list<string>
     */
    private static function candidates(string $field): array
    {
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $field));
        $keys = [$field, $snake];
        if (str_ends_with($snake, '_id')) {
            $keys[] = 'id';
        }
        if (str_ends_with($snake, '_url')) {
            $keys[] = 'url';
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param array<mixed> $payload
     *
     * @return array<mixed>|null
     */
    private static function firstRow(array $payload): ?array
    {
        $rows = array_is_list($payload) ? $payload : null;
        foreach (self::LIST_KEYS as $key) {
            if (null === $rows && is_array($payload[$key] ?? null) && array_is_list($payload[$key])) {
                $rows = $payload[$key];
            }
        }
        $first = $rows[0] ?? null;

        return is_array($first) ? $first : null;
    }
}
