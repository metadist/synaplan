<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Agent;

use App\DTO\AgentDefinitionV1;
use App\Service\Agent\Definition\AgentDefinition;
use App\Service\Agent\Definition\AgentDefinitionValidator;
use OpenApi\Attributes\Schema;
use PHPUnit\Framework\TestCase;

/**
 * `agent.v1` is one artifact: the OpenAPI component the frontend types are
 * generated from must accept exactly the keys the validator accepts, and
 * the defaults must satisfy the published component.
 */
final class AgentDefinitionSchemaParityTest extends TestCase
{
    public function testComponentAndValidatorAgreeOnEveryKeySet(): void
    {
        $root = $this->schema();

        self::assertSame(AgentDefinitionValidator::ROOT_KEYS, $this->propertyNames($root));
        self::assertSame(AgentDefinitionValidator::ROOT_KEYS, $this->required($root), 'every section is present on a validated document');

        self::assertSame(AgentDefinitionValidator::MODEL_KEYS, $this->propertyNames($this->section($root, 'models')));
        self::assertSame(AgentDefinitionValidator::KNOWLEDGE_KEYS, $this->propertyNames($this->section($root, 'knowledge')));
        self::assertSame(AgentDefinitionValidator::TOOL_KEYS, $this->propertyNames($this->section($root, 'tools')));
        self::assertSame(AgentDefinitionValidator::SKILL_KEYS, $this->propertyNames($this->section($root, 'skills')));
        self::assertSame(AgentDefinitionValidator::PARAMETER_KEYS, $this->propertyNames($this->section($root, 'parameters')));
        self::assertSame(AgentDefinitionValidator::BEHAVIOUR_KEYS, $this->propertyNames($this->section($root, 'behaviour')));
        self::assertSame(AgentDefinitionValidator::TRIGGER_KEYS, $this->propertyNames($this->section($root, 'triggers')));

        $triggers = $this->section($root, 'triggers');
        self::assertSame(AgentDefinitionValidator::EVENT_KEYS, $this->propertyNames($this->items($this->section($triggers, 'events'))));
        self::assertSame(AgentDefinitionValidator::SCHEDULE_KEYS, $this->propertyNames($this->items($this->section($triggers, 'schedules'))));

        $kind = $this->section($this->items($this->section($triggers, 'events')), 'kind');
        self::assertSame(AgentDefinitionValidator::EVENT_KINDS, $this->enum($kind));
    }

    public function testDefaultsAreAValidDocumentAndCarryEveryRequiredKey(): void
    {
        $defaults = (new AgentDefinitionValidator())->validate([])->toArray();

        self::assertSame(AgentDefinition::defaults()->toArray(), $defaults);

        $root = $this->schema();
        foreach ($this->required($root) as $section) {
            self::assertArrayHasKey($section, $defaults);
        }
        foreach ($this->children($root) as $property) {
            $section = $this->propertyName($property);
            foreach ($this->required($property) as $key) {
                self::assertArrayHasKey($key, $defaults[$section], $section.'.'.$key);
            }
        }
    }

    private function schema(): object
    {
        $attributes = (new \ReflectionClass(AgentDefinitionV1::class))->getAttributes(Schema::class);
        self::assertCount(1, $attributes);

        return $attributes[0]->newInstance();
    }

    /**
     * @return list<string>
     */
    private function propertyNames(object $parent): array
    {
        $names = [];
        foreach ($this->children($parent) as $property) {
            $names[] = $this->propertyName($property);
        }

        return $names;
    }

    /**
     * @return list<object>
     */
    private function children(object $parent): array
    {
        $properties = $parent->properties ?? null;
        if (!is_array($properties)) {
            self::fail('expected a property list');
        }

        $out = [];
        foreach ($properties as $property) {
            if (!is_object($property)) {
                self::fail('expected a named property');
            }
            $out[] = $property;
        }

        return $out;
    }

    private function section(object $parent, string $name): object
    {
        foreach ($this->children($parent) as $property) {
            if ($this->propertyName($property) === $name) {
                return $property;
            }
        }
        self::fail(sprintf('Property "%s" missing from the agent.v1 component', $name));
    }

    private function items(object $property): object
    {
        $items = $property->items ?? null;
        if (!is_object($items)) {
            self::fail('expected an items schema');
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function required(object $parent): array
    {
        $required = $parent->required ?? [];
        if (!is_array($required)) {
            return [];
        }

        $out = [];
        foreach ($required as $name) {
            if (is_string($name)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function enum(object $property): array
    {
        $enum = $property->enum ?? [];
        if (!is_array($enum)) {
            self::fail('expected an enum');
        }

        $out = [];
        foreach ($enum as $value) {
            if (is_string($value)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    private function propertyName(object $property): string
    {
        $name = $property->property ?? null;
        if (!is_string($name) || '' === $name) {
            self::fail('expected a named property');
        }

        return $name;
    }
}
