<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\Schema\MemoryExtractionSchema;
use PHPUnit\Framework\TestCase;

final class MemoryExtractionSchemaTest extends TestCase
{
    public function testBuildNamesTheSchemaMemoryExtraction(): void
    {
        $schema = MemoryExtractionSchema::build();

        $this->assertSame('memory_extraction', $schema->name);
    }

    /**
     * The array root is wrapped in an object property because OpenAI-dialect
     * structured output (and Anthropic tool-forcing) both reject a bare
     * top-level array.
     */
    public function testTopLevelIsAnObjectWrappingTheMemoriesArray(): void
    {
        $schema = MemoryExtractionSchema::build();

        $this->assertSame('object', $schema->schema['type']);
        $this->assertSame(['memories'], $schema->schema['required']);
        $this->assertSame('array', $schema->schema['properties']['memories']['type']);
    }

    public function testActionIsConstrainedToCreateUpdateDelete(): void
    {
        $schema = MemoryExtractionSchema::build();
        $itemProperties = $schema->schema['properties']['memories']['items']['properties'];

        $this->assertSame(['create', 'update', 'delete'], $itemProperties['action']['enum']);
    }

    /**
     * `memory_id`/`category`/`key`/`value` are not all populated for every
     * action (`create` never carries a `memory_id`, `delete` carries only
     * one) but strict mode forbids omittable keys — every field is
     * therefore nullable and always required.
     */
    public function testOptionalFieldsAreNullableTypesNotOmittableKeys(): void
    {
        $schema = MemoryExtractionSchema::build();
        $item = $schema->schema['properties']['memories']['items'];

        $this->assertSame(['integer', 'null'], $item['properties']['memory_id']['type']);
        $this->assertSame(['string', 'null'], $item['properties']['category']['type']);
        $this->assertSame(['string', 'null'], $item['properties']['key']['type']);
        $this->assertSame(['string', 'null'], $item['properties']['value']['type']);
        $this->assertSame(
            ['action', 'memory_id', 'category', 'key', 'value'],
            $item['required'],
        );
    }

    public function testDefaultsToStrictMode(): void
    {
        $schema = MemoryExtractionSchema::build();

        $this->assertTrue($schema->strict);
        $this->assertFalse($schema->schema['additionalProperties']);
        $this->assertFalse($schema->schema['properties']['memories']['items']['additionalProperties']);
    }
}
