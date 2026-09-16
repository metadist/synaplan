<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput;

/**
 * Rewrites a JSON Schema into the keyword subset Gemini's
 * `generationConfig.responseJsonSchema` documents as supported:
 *
 *   `$id`, `$defs`, `$ref`, `$anchor`, `type`, `format`, `title`,
 *   `description`, `enum` (strings and numbers), `items`, `prefixItems`,
 *   `minItems`, `maxItems`, `minimum`, `maximum`, `anyOf`, `oneOf`,
 *   `properties`, `additionalProperties`, `required`
 *
 * A union `type` (`{"type": ["string", "null"]}`) is the one shape our schemas
 * use that the list does not name, while `anyOf` is named explicitly. Gemini
 * happens to accept the union today (verified against gemini-2.5-flash and
 * gemini-3.1-flash-lite), but undocumented tolerance is not a contract, so a
 * union becomes one `anyOf` branch per member. The sibling constraints
 * (`enum`, `properties`, `required`, …) travel into the branch they actually
 * constrain — a `null` value must not be asked to satisfy an `enum` of
 * strings.
 */
final class GoogleJsonSchemaNormalizer
{
    /**
     * Keywords that describe the schema itself rather than the value, so they
     * stay at the union's level instead of being copied into every branch.
     */
    private const BRANCH_INDEPENDENT_KEYWORDS = ['title', 'description'];

    /** Sub-schemas reached through a name → schema map. */
    private const SCHEMA_MAPS = ['properties', '$defs', 'definitions'];

    /** Sub-schemas reached through a single nested schema. */
    private const SCHEMA_CHILDREN = ['items', 'additionalProperties', 'propertyNames'];

    /** Sub-schemas reached through a list of schemas. */
    private const SCHEMA_LISTS = ['anyOf', 'oneOf', 'allOf', 'prefixItems'];

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    public function normalize(array $schema): array
    {
        // Nullability is carried by the `null` branch after the union rewrite
        // below, so a literal null inside `enum` is both redundant and outside
        // the documented "enum (for strings and numbers)" support.
        if (isset($schema['enum']) && \is_array($schema['enum'])) {
            $schema['enum'] = array_values(array_filter(
                $schema['enum'],
                static fn (mixed $value): bool => null !== $value,
            ));
        }

        $schema = $this->rewriteUnionType($schema);

        foreach (self::SCHEMA_MAPS as $keyword) {
            if (!isset($schema[$keyword]) || !\is_array($schema[$keyword])) {
                continue;
            }
            foreach ($schema[$keyword] as $name => $child) {
                if (\is_array($child)) {
                    $schema[$keyword][$name] = $this->normalize($child);
                }
            }
        }

        foreach (self::SCHEMA_CHILDREN as $keyword) {
            if (isset($schema[$keyword]) && \is_array($schema[$keyword])) {
                $schema[$keyword] = $this->normalize($schema[$keyword]);
            }
        }

        foreach (self::SCHEMA_LISTS as $keyword) {
            if (!isset($schema[$keyword]) || !\is_array($schema[$keyword])) {
                continue;
            }
            foreach ($schema[$keyword] as $index => $branch) {
                if (\is_array($branch)) {
                    $schema[$keyword][$index] = $this->normalize($branch);
                }
            }
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function rewriteUnionType(array $schema): array
    {
        if (!isset($schema['type']) || !\is_array($schema['type'])) {
            return $schema;
        }

        $types = array_values(array_unique(array_map(
            static fn (mixed $type): string => \is_string($type) ? $type : (string) json_encode($type),
            $schema['type'],
        )));

        if (\count($types) < 2) {
            // A single-member union is just that type; `[]` would be a schema
            // that nothing can satisfy, so keep it verbatim for the provider
            // to reject loudly rather than silently widening it.
            if (1 === \count($types)) {
                $schema['type'] = $types[0];
            }

            return $schema;
        }

        $shared = array_intersect_key($schema, array_flip(self::BRANCH_INDEPENDENT_KEYWORDS));
        $constraints = array_diff_key($schema, array_flip([...self::BRANCH_INDEPENDENT_KEYWORDS, 'type']));

        $branches = [];
        foreach ($types as $type) {
            $branches[] = 'null' === $type
                ? ['type' => 'null']
                : ['type' => $type, ...$constraints];
        }

        return [...$shared, 'anyOf' => $branches];
    }
}
