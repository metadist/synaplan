<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\Schema\TaskPlanSchema;
use App\Service\Multitask\Plan\Capability;
use PHPUnit\Framework\TestCase;

final class TaskPlanSchemaTest extends TestCase
{
    public function testBuildNamesTheSchemaTaskPlan(): void
    {
        $schema = TaskPlanSchema::build();

        $this->assertSame('task_plan', $schema->name);
    }

    public function testTopLevelPropertiesMatchTheTaskPlanPayloadContract(): void
    {
        $schema = TaskPlanSchema::build();
        $properties = $schema->schema['properties'];

        $this->assertSame(['version', 'language', 'reply_node', 'tasks'], array_keys($properties));
        $this->assertSame(['version', 'language', 'reply_node', 'tasks'], $schema->schema['required']);
    }

    public function testVersionIsConstrainedToOne(): void
    {
        $schema = TaskPlanSchema::build();

        $this->assertSame([1], $schema->schema['properties']['version']['enum']);
    }

    public function testCapabilityIsConstrainedToTheFullCapabilityVocabulary(): void
    {
        $schema = TaskPlanSchema::build();
        $taskProperties = $schema->schema['properties']['tasks']['items']['properties'];

        $this->assertSame(Capability::values(), $taskProperties['capability']['enum']);
        // A vocabulary drift (capability renamed/added) must be caught here, not silently.
        $this->assertContains('chat', $taskProperties['capability']['enum']);
        $this->assertContains('document_generation', $taskProperties['capability']['enum']);
    }

    /**
     * `inputs`/`params` shape depends on the node's capability (a
     * `calendar_event` node's params differ entirely from a `text2sound`
     * node's) — there is no single fixed property set, so they stay open
     * `object` types with no `properties`/`additionalProperties` constraint.
     */
    public function testInputsAndParamsStayUnconstrainedObjects(): void
    {
        $schema = TaskPlanSchema::build();
        $taskProperties = $schema->schema['properties']['tasks']['items']['properties'];

        $this->assertSame(['type' => 'object'], $taskProperties['inputs']);
        $this->assertSame(['type' => 'object'], $taskProperties['params']);
    }

    /**
     * Strict mode (OpenAI/Groq `strict: true`) requires `additionalProperties:
     * false` on every object in the schema tree — incompatible with the
     * genuinely open `inputs`/`params` objects above. This schema must
     * therefore opt out of strict mode explicitly.
     */
    public function testSchemaOptsOutOfStrictMode(): void
    {
        $schema = TaskPlanSchema::build();

        $this->assertFalse($schema->strict);
    }

    public function testOnlyIdAndCapabilityAreRequiredPerTask(): void
    {
        $schema = TaskPlanSchema::build();

        $this->assertSame(['id', 'capability'], $schema->schema['properties']['tasks']['items']['required']);
    }
}
