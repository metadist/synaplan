<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\Schema\FeedbackContradictionSchema;
use PHPUnit\Framework\TestCase;

final class FeedbackContradictionSchemaTest extends TestCase
{
    public function testBuildNamesTheSchemaFeedbackContradiction(): void
    {
        $schema = FeedbackContradictionSchema::build();

        $this->assertSame('feedback_contradiction', $schema->name);
    }

    public function testTopLevelIsAnObjectWrappingTheContradictionsArray(): void
    {
        $schema = FeedbackContradictionSchema::build();

        $this->assertSame('object', $schema->schema['type']);
        $this->assertSame(['contradictions'], $schema->schema['required']);
    }

    public function testTypeIsConstrainedToTheThreeFeedbackItemTypes(): void
    {
        $schema = FeedbackContradictionSchema::build();
        $item = $schema->schema['properties']['contradictions']['items'];

        $this->assertSame(['memory', 'false_positive', 'positive'], $item['properties']['type']['enum']);
        $this->assertSame(['id', 'type', 'value', 'reason'], $item['required']);
    }

    public function testDefaultsToStrictMode(): void
    {
        $schema = FeedbackContradictionSchema::build();

        $this->assertTrue($schema->strict);
    }
}
