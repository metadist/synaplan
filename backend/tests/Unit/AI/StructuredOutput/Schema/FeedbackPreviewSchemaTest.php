<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\Schema\FeedbackPreviewSchema;
use PHPUnit\Framework\TestCase;

final class FeedbackPreviewSchemaTest extends TestCase
{
    public function testBuildNamesTheSchemaFeedbackPreview(): void
    {
        $schema = FeedbackPreviewSchema::build();

        $this->assertSame('feedback_preview', $schema->name);
    }

    public function testClassificationIsConstrainedToMemoryOrFeedback(): void
    {
        $schema = FeedbackPreviewSchema::build();

        $this->assertSame(['memory', 'feedback'], $schema->schema['properties']['classification']['enum']);
    }

    public function testAllThreeFieldsAreRequired(): void
    {
        $schema = FeedbackPreviewSchema::build();

        $this->assertSame(
            ['classification', 'summaryOptions', 'correctionOptions'],
            $schema->schema['required'],
        );
    }
}
