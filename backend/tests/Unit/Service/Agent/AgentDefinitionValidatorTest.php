<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Agent;

use App\Service\Agent\Definition\AgentDefinition;
use App\Service\Agent\Definition\AgentDefinitionValidator;
use App\Service\Agent\Exception\AgentDefinitionException;
use PHPUnit\Framework\TestCase;

final class AgentDefinitionValidatorTest extends TestCase
{
    private AgentDefinitionValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new AgentDefinitionValidator();
    }

    public function testDefaultsRoundTrip(): void
    {
        $defaults = AgentDefinition::defaults()->toArray();
        $validated = $this->validator->validate($defaults);

        self::assertSame(AgentDefinition::SCHEMA, $validated->toArray()['schema']);
        self::assertSame($defaults, $validated->toArray());
    }

    public function testSchemaOnlyFillsDefaults(): void
    {
        $json = $this->fixture('valid-defaults.json');
        $validated = $this->validator->validate($json);

        self::assertSame(AgentDefinition::defaults()->toArray(), $validated->toArray());
    }

    public function testValidFullDocument(): void
    {
        $json = $this->fixture('valid-full.json');
        $validated = $this->validator->validate($json);

        self::assertSame('anthropic:claude-sonnet-4:chat', $validated->models()['chat']);
        self::assertSame('user', $validated->toArray()['behaviour']['memory']);
        self::assertSame(['12:legal-contracts'], $validated->toArray()['knowledge']['folders']);
    }

    public function testUnknownRootKeyIsRejectedWithPath(): void
    {
        $this->expectException(AgentDefinitionException::class);
        $this->expectExceptionMessage('Unknown key "tasks" in agent.v1');

        try {
            $this->validator->validate($this->fixture('unknown-root.json'));
        } catch (AgentDefinitionException $e) {
            self::assertSame('tasks', $e->path);
            throw $e;
        }
    }

    public function testUnknownNestedKeyIsRejectedWithPath(): void
    {
        $this->expectException(AgentDefinitionException::class);
        $this->expectExceptionMessage('Unknown key "tools.foo" in agent.v1');

        try {
            $this->validator->validate($this->fixture('unknown-nested.json'));
        } catch (AgentDefinitionException $e) {
            self::assertSame('tools.foo', $e->path);
            throw $e;
        }
    }

    public function testBadModelKeyShapeIsRejected(): void
    {
        $this->expectException(AgentDefinitionException::class);
        $this->expectExceptionMessage('models.chat must be a catalog key');

        try {
            $this->validator->validate($this->fixture('bad-model-key.json'));
        } catch (AgentDefinitionException $e) {
            self::assertSame('models.chat', $e->path);
            throw $e;
        }
    }

    public function testMemoryMustBeUser(): void
    {
        $this->expectException(AgentDefinitionException::class);
        $this->expectExceptionMessage('behaviour.memory accepts only "user"');

        try {
            $this->validator->validate($this->fixture('bad-memory.json'));
        } catch (AgentDefinitionException $e) {
            self::assertSame('behaviour.memory', $e->path);
            throw $e;
        }
    }

    public function testFolderShapeIsRejected(): void
    {
        $this->expectException(AgentDefinitionException::class);

        try {
            $this->validator->validate($this->fixture('bad-folder.json'));
        } catch (AgentDefinitionException $e) {
            self::assertSame('knowledge.folders.0', $e->path);
            throw $e;
        }
    }

    public function testWrongSchemaIsRejected(): void
    {
        $this->expectException(AgentDefinitionException::class);

        try {
            $this->validator->validate(['schema' => 'agent.v2']);
        } catch (AgentDefinitionException $e) {
            self::assertSame('schema', $e->path);
            throw $e;
        }
    }

    /**
     * @return array<mixed>
     */
    private function fixture(string $name): array
    {
        $path = dirname(__DIR__, 3).'/Fixtures/agents/'.$name;
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
