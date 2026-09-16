<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\Schema\WidgetMemorySuggestionSchema;
use PHPUnit\Framework\TestCase;

final class WidgetMemorySuggestionSchemaTest extends TestCase
{
    public function testBuildNamesTheSchemaWidgetMemorySuggestions(): void
    {
        $schema = WidgetMemorySuggestionSchema::build();

        $this->assertSame('widget_memory_suggestions', $schema->name);
    }

    public function testTopLevelIsAnObjectWrappingTheSuggestionsArray(): void
    {
        $schema = WidgetMemorySuggestionSchema::build();

        $this->assertSame('object', $schema->schema['type']);
        $this->assertSame(['suggestions'], $schema->schema['required']);
    }

    public function testResponseTypeIsConstrainedToTheThreeKnownTypes(): void
    {
        $schema = WidgetMemorySuggestionSchema::build();
        $item = $schema->schema['properties']['suggestions']['items'];

        $this->assertSame(['text', 'link', 'list'], $item['properties']['responseType']['enum']);
    }

    /**
     * `meta` is `{}` for a plain text response and `{"url": "..."}` for a
     * link — a genuinely open, variable-shape object, so strict mode
     * (which would require `additionalProperties: false` everywhere) is
     * deliberately opted out of.
     */
    public function testMetaStaysAnUnconstrainedObjectAndSchemaOptsOutOfStrictMode(): void
    {
        $schema = WidgetMemorySuggestionSchema::build();
        $item = $schema->schema['properties']['suggestions']['items'];

        $this->assertSame(['type' => 'object'], $item['properties']['meta']);
        $this->assertFalse($schema->strict);
    }
}
