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
     * officemaker prompt already produces this flat root object, so no
     * envelope adjustment is needed.
     */
    public function testSchemaIsAFlatObjectWithEveryKeyRequired(): void
    {
        $schema = FileGenerationSchema::build();

        $this->assertSame('object', $schema->schema['type']);
        $this->assertSame('string', $schema->schema['properties']['BFILEPATH']['type']);
        $this->assertSame('string', $schema->schema['properties']['BFILETEXT']['type']);
        $this->assertSame(['BFILEPATH', 'BFILETEXT', 'BEXPORT'], $schema->schema['required']);
    }

    /**
     * The prompt's PDF section asks for `"BEXPORT":"pdf"`; a closed schema
     * without that key made Groq's best-effort mode reject the whole answer
     * (`json_validate_failed`) the moment the model complied. Nullable, so
     * strict mode's all-required rule holds for the no-export case.
     */
    public function testExportIsANullablePdfOnlyEnum(): void
    {
        $schema = FileGenerationSchema::build();

        $this->assertSame(['string', 'null'], $schema->schema['properties']['BEXPORT']['type']);
        $this->assertSame(['pdf', null], $schema->schema['properties']['BEXPORT']['enum']);
    }

    public function testDefaultsToStrictMode(): void
    {
        $schema = FileGenerationSchema::build();

        $this->assertTrue($schema->strict);
        $this->assertFalse($schema->schema['additionalProperties']);
    }
}
