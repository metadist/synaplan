<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\Schema\SortClassificationSchema;
use PHPUnit\Framework\TestCase;

final class SortClassificationSchemaTest extends TestCase
{
    public function testBuildConstrainsBtopicToTheGivenTopicList(): void
    {
        $schema = SortClassificationSchema::build(['general', 'mediamaker', 'docsummary'], ['en', 'de']);

        $this->assertSame('sort_classification', $schema->name);
        $this->assertSame(['general', 'mediamaker', 'docsummary'], $schema->schema['properties']['BTOPIC']['enum']);
        $this->assertSame('string', $schema->schema['properties']['BTOPIC']['type']);
    }

    public function testBuildConstrainsBlangToTheGivenLanguageList(): void
    {
        $schema = SortClassificationSchema::build(['general'], ['de', 'en', 'it', 'es', 'fr', 'nl', 'pt', 'ru', 'sv', 'tr']);

        $this->assertSame(['de', 'en', 'it', 'es', 'fr', 'nl', 'pt', 'ru', 'sv', 'tr'], $schema->schema['properties']['BLANG']['enum']);
    }

    /**
     * Optional fields must be nullable TYPES (`["string", "null"]`), never
     * omittable keys — strict mode (OpenAI/Groq) requires every property in
     * `required` plus `additionalProperties: false`, which is incompatible
     * with "leave the key out when not applicable".
     */
    public function testOptionalFieldsAreNullableTypesNotOmittableKeys(): void
    {
        $schema = SortClassificationSchema::build(['general'], ['en']);
        $properties = $schema->schema['properties'];

        $this->assertSame(['boolean', 'null'], $properties['BMULTI']['type']);
        $this->assertSame(['string', 'null'], $properties['BMEDIA']['type']);
        $this->assertSame(['string', 'null'], $properties['BINPUTMODE']['type']);
        $this->assertSame(['integer', 'null'], $properties['BDURATION']['type']);
        $this->assertSame(['string', 'null'], $properties['BRESOLUTION']['type']);

        $this->assertContains(null, $properties['BMEDIA']['enum']);
        $this->assertContains(null, $properties['BINPUTMODE']['enum']);
        $this->assertContains(null, $properties['BRESOLUTION']['enum']);
    }

    public function testEveryPropertyIsListedInRequiredDespiteBeingNullable(): void
    {
        $schema = SortClassificationSchema::build(['general'], ['en']);

        $this->assertSame(
            ['BTOPIC', 'BLANG', 'BWEBSEARCH', 'BMULTI', 'BMEDIA', 'BINPUTMODE', 'BDURATION', 'BRESOLUTION'],
            $schema->schema['required'],
        );
        $this->assertSame(array_keys($schema->schema['properties']), $schema->schema['required']);
    }

    public function testAdditionalPropertiesAreForbidden(): void
    {
        $schema = SortClassificationSchema::build(['general'], ['en']);

        $this->assertFalse($schema->schema['additionalProperties']);
    }

    public function testBmediaEnumCoversTheThreeMediaTypes(): void
    {
        $schema = SortClassificationSchema::build(['mediamaker'], ['en']);

        $this->assertSame(['image', 'video', 'audio', null], $schema->schema['properties']['BMEDIA']['enum']);
    }

    public function testBresolutionEnumCoversTheThreeCanonicalResolutions(): void
    {
        $schema = SortClassificationSchema::build(['mediamaker'], ['en']);

        $this->assertSame(['720p', '1080p', '4K', null], $schema->schema['properties']['BRESOLUTION']['enum']);
    }

    public function testBinputmodeEnumCoversBothModes(): void
    {
        $schema = SortClassificationSchema::build(['mediamaker'], ['en']);

        $this->assertSame(['text_only', 'reference_images', null], $schema->schema['properties']['BINPUTMODE']['enum']);
    }

    /**
     * Defensive fallback: an empty topic/language list (a call site that
     * never loaded the catalog) must not produce a schema with an empty
     * `enum` array, which would reject every possible value.
     */
    public function testEmptyTopicListOmitsTheEnumConstraintInstead(): void
    {
        $schema = SortClassificationSchema::build([], []);

        $this->assertArrayNotHasKey('enum', $schema->schema['properties']['BTOPIC']);
        $this->assertArrayNotHasKey('enum', $schema->schema['properties']['BLANG']);
        $this->assertSame('string', $schema->schema['properties']['BTOPIC']['type']);
    }
}
