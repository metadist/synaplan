<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Tool;

use App\AI\Tool\OpenAiToolShapes;
use PHPUnit\Framework\TestCase;

final class OpenAiToolShapesTest extends TestCase
{
    public function testValidateToolsRejectsMalformedInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OpenAiToolShapes::validateTools('not-an-array');
    }

    public function testValidateToolsRequiresFunctionName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OpenAiToolShapes::validateTools([['type' => 'function', 'function' => []]]);
    }

    public function testValidateToolChoiceAcceptsOpenAiVariants(): void
    {
        self::assertSame('auto', OpenAiToolShapes::validateToolChoice('auto'));
        self::assertSame('none', OpenAiToolShapes::validateToolChoice('none'));
        self::assertSame('required', OpenAiToolShapes::validateToolChoice('required'));
        $named = ['type' => 'function', 'function' => ['name' => 'lookup']];
        self::assertSame($named, OpenAiToolShapes::validateToolChoice($named));
    }

    public function testNormalizeToolChoiceMapsAnthropicVariants(): void
    {
        self::assertSame('auto', OpenAiToolShapes::normalizeToolChoice(['type' => 'auto']));
        self::assertSame('required', OpenAiToolShapes::normalizeToolChoice(['type' => 'any']));
        self::assertSame(
            ['type' => 'function', 'function' => ['name' => 'lookup']],
            OpenAiToolShapes::normalizeToolChoice(['type' => 'tool', 'name' => 'lookup']),
        );
        self::assertSame('auto', OpenAiToolShapes::mapAnthropicToolChoice('auto'));
    }

    public function testToChatCompletionsToolsFromAnthropicShape(): void
    {
        $mapped = OpenAiToolShapes::toChatCompletionsTools([[
            'name' => 'mcp__1__rag_search',
            'description' => 'search',
            'input_schema' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]],
        ]]);

        self::assertSame('function', $mapped[0]['type']);
        self::assertSame('mcp__1__rag_search', $mapped[0]['function']['name']);
        self::assertSame('search', $mapped[0]['function']['description']);
        self::assertSame(['type' => 'object', 'properties' => ['q' => ['type' => 'string']]], $mapped[0]['function']['parameters']);
    }

    public function testToChatCompletionsToolsFromOpenAiShape(): void
    {
        $mapped = OpenAiToolShapes::toChatCompletionsTools([[
            'type' => 'function',
            'function' => [
                'name' => 'lookup',
                'description' => 'find',
                'parameters' => ['type' => 'object', 'properties' => []],
            ],
        ]]);

        self::assertSame('lookup', $mapped[0]['function']['name']);
        self::assertSame('find', $mapped[0]['function']['description']);
    }

    public function testToResponsesTools(): void
    {
        $mapped = OpenAiToolShapes::toResponsesTools([[
            'type' => 'function',
            'function' => [
                'name' => 'lookup',
                'description' => 'find',
                'parameters' => ['type' => 'object'],
            ],
        ]]);

        self::assertSame([
            'type' => 'function',
            'name' => 'lookup',
            'description' => 'find',
            'parameters' => ['type' => 'object'],
            'strict' => false,
        ], $mapped[0]);
    }

    public function testToAnthropicTools(): void
    {
        $mapped = OpenAiToolShapes::toAnthropicTools([[
            'type' => 'function',
            'function' => [
                'name' => 'lookup',
                'description' => 'find',
                'parameters' => ['type' => 'object', 'properties' => []],
            ],
        ]]);

        self::assertSame('lookup', $mapped[0]['name']);
        self::assertSame('find', $mapped[0]['description']);
        self::assertSame(['type' => 'object', 'properties' => []], $mapped[0]['input_schema']);
    }

    public function testToGeminiDeclarationsMatchesTranslatorSanitising(): void
    {
        $mapped = OpenAiToolShapes::toGeminiDeclarations([[
            'name' => 'mcp__2__memory_search',
            'description' => 'mem',
            'input_schema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]],
        ]]);

        self::assertSame('mcp__2__memory_search', $mapped[0]['name']);
        self::assertArrayHasKey('parametersJsonSchema', $mapped[0]);
        self::assertSame('string', $mapped[0]['parametersJsonSchema']['properties']['query']['type']);
    }

    public function testToResponsesToolChoiceFlattensNamedFunction(): void
    {
        self::assertSame('auto', OpenAiToolShapes::toResponsesToolChoice('auto'));
        self::assertSame('required', OpenAiToolShapes::toResponsesToolChoice('required'));
        self::assertSame(
            ['type' => 'function', 'name' => 'lookup'],
            OpenAiToolShapes::toResponsesToolChoice([
                'type' => 'function',
                'function' => ['name' => 'lookup'],
            ]),
        );
    }

    public function testToAnthropicToolChoice(): void
    {
        self::assertSame(['type' => 'auto'], OpenAiToolShapes::toAnthropicToolChoice('auto'));
        self::assertSame(['type' => 'none'], OpenAiToolShapes::toAnthropicToolChoice('none'));
        self::assertSame(['type' => 'any'], OpenAiToolShapes::toAnthropicToolChoice('required'));
        self::assertSame(
            ['type' => 'tool', 'name' => 'lookup'],
            OpenAiToolShapes::toAnthropicToolChoice([
                'type' => 'function',
                'function' => ['name' => 'lookup'],
            ]),
        );
    }

    public function testToGeminiToolConfig(): void
    {
        self::assertSame(
            ['functionCallingConfig' => ['mode' => 'AUTO']],
            OpenAiToolShapes::toGeminiToolConfig('auto'),
        );
        self::assertSame(
            ['functionCallingConfig' => ['mode' => 'NONE']],
            OpenAiToolShapes::toGeminiToolConfig('none'),
        );
        self::assertSame(
            ['functionCallingConfig' => ['mode' => 'ANY']],
            OpenAiToolShapes::toGeminiToolConfig('required'),
        );
        self::assertSame(
            ['functionCallingConfig' => [
                'mode' => 'ANY',
                'allowedFunctionNames' => ['lookup'],
            ]],
            OpenAiToolShapes::toGeminiToolConfig([
                'type' => 'function',
                'function' => ['name' => 'lookup'],
            ]),
        );
    }
}
