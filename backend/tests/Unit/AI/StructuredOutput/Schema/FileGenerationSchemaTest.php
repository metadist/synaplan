<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\Schema\FileGenerationSchema;
use PHPUnit\Framework\TestCase;

final class FileGenerationSchemaTest extends TestCase
{
    public function testBuildNamesTheSchemaOfficeFileGeneration(): void
    {
        $schema = FileGenerationSchema::build();

        $this->assertSame('office_file_generation', $schema->name);
    }

    /**
     * Unlike the array-wrapping schemas elsewhere in this namespace, the
     * officemaker prompt already produces exactly this two-key root object,
     * so no envelope adjustment is needed.
     */
    public function testSchemaIsAFlatObjectWithBothKeysRequired(): void
    {
        $schema = FileGenerationSchema::build();

        $this->assertSame('object', $schema->schema['type']);
        $this->assertSame('string', $schema->schema['properties']['BFILEPATH']['type']);
        $this->assertSame('string', $schema->schema['properties']['BFILETEXT']['type']);
        $this->assertSame(['BFILEPATH', 'BFILETEXT'], $schema->schema['required']);
    }

    public function testDefaultsToStrictMode(): void
    {
        $schema = FileGenerationSchema::build();

        $this->assertTrue($schema->strict);
        $this->assertFalse($schema->schema['additionalProperties']);
    }
}
