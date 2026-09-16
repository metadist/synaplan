<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\Schema\WidgetPromptMetadataSchema;
use PHPUnit\Framework\TestCase;

final class WidgetPromptMetadataSchemaTest extends TestCase
{
    public function testBuildNamesTheSchemaWidgetPromptMetadata(): void
    {
        $schema = WidgetPromptMetadataSchema::build();

        $this->assertSame('widget_prompt_metadata', $schema->name);
    }

    public function testTitleAndDescriptionAreRequiredStrings(): void
    {
        $schema = WidgetPromptMetadataSchema::build();

        $this->assertSame('string', $schema->schema['properties']['title']['type']);
        $this->assertSame('string', $schema->schema['properties']['description']['type']);
        $this->assertSame(['title', 'description'], $schema->schema['required']);
    }

    public function testDefaultsToStrictMode(): void
    {
        $schema = WidgetPromptMetadataSchema::build();

        $this->assertTrue($schema->strict);
    }
}
