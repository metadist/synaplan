<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\Schema\UserMemoryActionSchema;
use PHPUnit\Framework\TestCase;

final class UserMemoryActionSchemaTest extends TestCase
{
    public function testBuildNamesTheSchemaUserMemoryActions(): void
    {
        $schema = UserMemoryActionSchema::build();

        $this->assertSame('user_memory_actions', $schema->name);
    }

    /**
     * The array root is wrapped in an object property because OpenAI-dialect
     * structured output (and Anthropic tool-forcing) both reject a bare
     * top-level array.
     */
    public function testTopLevelIsAnObjectWrappingTheActionsArray(): void
    {
        $schema = UserMemoryActionSchema::build();

        $this->assertSame('object', $schema->schema['type']);
        $this->assertSame(['actions'], $schema->schema['required']);
        $this->assertSame('array', $schema->schema['properties']['actions']['type']);
    }

    public function testActionIsConstrainedToCreateUpdateDelete(): void
    {
        $schema = UserMemoryActionSchema::build();
        $itemProperties = $schema->schema['properties']['actions']['items']['properties'];

        $this->assertSame(['create', 'update', 'delete'], $itemProperties['action']['enum']);
    }

    public function testMemoryIsANullableNestedObjectWithFixedFields(): void
    {
        $schema = UserMemoryActionSchema::build();
        $memory = $schema->schema['properties']['actions']['items']['properties']['memory'];

        $this->assertSame(['object', 'null'], $memory['type']);
        $this->assertFalse($memory['additionalProperties']);
        $this->assertSame(['category', 'key', 'value'], $memory['required']);
        $this->assertSame('string', $memory['properties']['category']['type']);
        $this->assertSame('string', $memory['properties']['key']['type']);
        $this->assertSame('string', $memory['properties']['value']['type']);
    }

    /**
     * `existingId`/`memory`/`reason` are not all populated for every action
     * (`create` never carries an `existingId`, `delete` never carries a
     * `memory` payload) but strict mode forbids omittable keys — every
     * field is therefore nullable and always required.
     */
    public function testOptionalFieldsAreNullableTypesNotOmittableKeys(): void
    {
        $schema = UserMemoryActionSchema::build();
        $item = $schema->schema['properties']['actions']['items'];

        $this->assertSame(['integer', 'null'], $item['properties']['existingId']['type']);
        $this->assertSame(['string', 'null'], $item['properties']['reason']['type']);
        $this->assertSame(
            ['action', 'existingId', 'memory', 'reason'],
            $item['required'],
        );
    }

    public function testDefaultsToStrictMode(): void
    {
        $schema = UserMemoryActionSchema::build();

        $this->assertTrue($schema->strict);
        $this->assertFalse($schema->schema['additionalProperties']);
        $this->assertFalse($schema->schema['properties']['actions']['items']['additionalProperties']);
    }
}
