<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\StructuredOutput;

use App\AI\StructuredOutput\GoogleJsonSchemaNormalizer;
use App\AI\StructuredOutput\Schema\FeedbackContradictionSchema;
use App\AI\StructuredOutput\Schema\MemoryExtractionSchema;
use App\AI\StructuredOutput\Schema\SortClassificationSchema;
use App\AI\StructuredOutput\Schema\UserMemoryActionSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GoogleJsonSchemaNormalizerTest extends TestCase
{
    /** Sub-schema keywords the normalizer is expected to walk into. */
    private const SCHEMA_MAPS = ['properties', '$defs', 'definitions'];

    private const SCHEMA_CHILDREN = ['items', 'additionalProperties', 'propertyNames'];

    private const SCHEMA_LISTS = ['anyOf', 'oneOf', 'allOf', 'prefixItems'];

    private GoogleJsonSchemaNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new GoogleJsonSchemaNormalizer();
    }

    public function testASchemaWithoutUnionTypesIsUnchanged(): void
    {
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => ['topic' => ['type' => 'string', 'enum' => ['general', 'chat']]],
            'required' => ['topic'],
        ];

        self::assertSame($schema, $this->normalizer->normalize($schema));
    }

    public function testNullableStringBecomesAnAnyOfWithTheEnumOnTheStringBranch(): void
    {
        $result = $this->normalizer->normalize([
            'type' => ['string', 'null'],
            'enum' => ['image', 'video', null],
        ]);

        self::assertSame([
            'anyOf' => [
                ['type' => 'string', 'enum' => ['image', 'video']],
                ['type' => 'null'],
            ],
        ], $result);
    }

    public function testBranchIndependentKeywordsStayAboveTheAnyOf(): void
    {
        $result = $this->normalizer->normalize([
            'type' => ['integer', 'null'],
            'title' => 'Duration',
            'description' => 'Seconds, or null when not applicable.',
            'minimum' => 1,
        ]);

        self::assertSame([
            'title' => 'Duration',
            'description' => 'Seconds, or null when not applicable.',
            'anyOf' => [
                ['type' => 'integer', 'minimum' => 1],
                ['type' => 'null'],
            ],
        ], $result);
    }

    public function testNullableObjectCarriesItsPropertiesIntoTheObjectBranch(): void
    {
        $result = $this->normalizer->normalize([
            'type' => ['object', 'null'],
            'additionalProperties' => false,
            'properties' => ['key' => ['type' => 'string']],
            'required' => ['key'],
        ]);

        self::assertSame([
            'anyOf' => [
                [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => ['key' => ['type' => 'string']],
                    'required' => ['key'],
                ],
                ['type' => 'null'],
            ],
        ], $result);
    }

    public function testUnionsNestedInPropertiesItemsAndAnyOfAreRewritten(): void
    {
        $result = $this->normalizer->normalize([
            'type' => 'object',
            'properties' => [
                'rows' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => ['note' => ['type' => ['string', 'null']]],
                    ],
                ],
                'either' => ['anyOf' => [['type' => ['integer', 'null']]]],
            ],
        ]);

        self::assertSame(
            ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
            $result['properties']['rows']['items']['properties']['note'],
        );
        self::assertSame(
            ['anyOf' => [['type' => 'integer'], ['type' => 'null']]],
            $result['properties']['either']['anyOf'][0],
        );
    }

    public function testASingleMemberUnionCollapsesToThatType(): void
    {
        self::assertSame(['type' => 'string'], $this->normalizer->normalize(['type' => ['string']]));
    }

    /**
     * A property may legitimately be NAMED "type"
     * ({@see FeedbackContradictionSchema}); that is a property name, not a
     * type keyword, and must survive untouched.
     */
    public function testAPropertyNamedTypeIsNotMistakenForATypeKeyword(): void
    {
        $schema = FeedbackContradictionSchema::build()->schema;

        $result = $this->normalizer->normalize($schema);

        self::assertSame(
            $schema['properties']['contradictions']['items']['properties']['type'],
            $result['properties']['contradictions']['items']['properties']['type'],
        );
    }

    public function testNormalizingIsIdempotent(): void
    {
        $once = $this->normalizer->normalize(SortClassificationSchema::build(['general'], ['en'])->schema);

        self::assertSame($once, $this->normalizer->normalize($once));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function shippedSchemaProvider(): iterable
    {
        yield 'sort_classification' => [SortClassificationSchema::build(['general', 'mediamaker'], ['en', 'de'])->schema];
        yield 'memory_extraction' => [MemoryExtractionSchema::build()->schema];
        yield 'user_memory_action' => [UserMemoryActionSchema::build()->schema];
    }

    /**
     * The whole point of the normalizer: no union `type` may reach Gemini,
     * because `responseJsonSchema` documents `type` and `anyOf` but never a
     * type union.
     *
     * @param array<string, mixed> $schema
     */
    #[DataProvider('shippedSchemaProvider')]
    public function testShippedSchemasContainNoUnionTypesAfterNormalizing(array $schema): void
    {
        self::assertNotSame([], self::findUnionTypes($schema), 'Pointless guard: this schema has no union type to begin with.');
        self::assertSame([], self::findUnionTypes($this->normalizer->normalize($schema)));
    }

    /**
     * Walks only the keywords that actually hold sub-schemas, so a property
     * named "type" is never read as a type keyword.
     *
     * @param array<string, mixed> $schema
     *
     * @return list<string> paths that still carry a union `type`
     */
    private static function findUnionTypes(array $schema, string $path = '$'): array
    {
        $found = [];

        if (isset($schema['type']) && \is_array($schema['type'])) {
            $found[] = $path.'.type';
        }

        foreach (self::SCHEMA_MAPS as $keyword) {
            foreach (($schema[$keyword] ?? []) as $name => $child) {
                if (\is_array($child)) {
                    $found = [...$found, ...self::findUnionTypes($child, $path.'.'.$keyword.'.'.$name)];
                }
            }
        }

        foreach (self::SCHEMA_CHILDREN as $keyword) {
            if (\is_array($schema[$keyword] ?? null)) {
                $found = [...$found, ...self::findUnionTypes($schema[$keyword], $path.'.'.$keyword)];
            }
        }

        foreach (self::SCHEMA_LISTS as $keyword) {
            foreach (($schema[$keyword] ?? []) as $index => $branch) {
                if (\is_array($branch)) {
                    $found = [...$found, ...self::findUnionTypes($branch, $path.'.'.$keyword.'['.$index.']')];
                }
            }
        }

        return $found;
    }
}
