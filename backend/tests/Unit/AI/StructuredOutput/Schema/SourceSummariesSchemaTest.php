<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\Schema\SourceSummariesSchema;
use PHPUnit\Framework\TestCase;

final class SourceSummariesSchemaTest extends TestCase
{
    public function testBuildNamesTheSchemaSourceSummaries(): void
    {
        $schema = SourceSummariesSchema::build();

        $this->assertSame('source_summaries', $schema->name);
    }

    public function testTopLevelIsAnObjectWrappingASummariesStringArray(): void
    {
        $schema = SourceSummariesSchema::build();

        $this->assertSame('object', $schema->schema['type']);
        $this->assertSame(['summaries'], $schema->schema['required']);
        $this->assertSame('array', $schema->schema['properties']['summaries']['type']);
        $this->assertSame('string', $schema->schema['properties']['summaries']['items']['type']);
    }
}
