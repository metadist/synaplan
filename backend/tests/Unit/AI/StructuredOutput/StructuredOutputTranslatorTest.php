<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\StructuredOutput;

use App\AI\StructuredOutput\StructuredOutputCapability;
use App\AI\StructuredOutput\StructuredOutputSchema;
use App\AI\StructuredOutput\StructuredOutputTranslator;
use PHPUnit\Framework\TestCase;

final class StructuredOutputTranslatorTest extends TestCase
{
    private StructuredOutputTranslator $translator;

    protected function setUp(): void
    {
        $this->translator = new StructuredOutputTranslator(new StructuredOutputCapability());
    }

    private function schema(bool $strict = true): StructuredOutputSchema
    {
        return new StructuredOutputSchema(
            name: 'sort_classification',
            schema: ['type' => 'object', 'properties' => ['topic' => ['type' => 'string']], 'required' => ['topic']],
            strict: $strict,
        );
    }

    public function testUnsupportedProviderReturnsEmptyArray(): void
    {
        self::assertSame([], $this->translator->translate('triton', 'any-model', false, $this->schema()));
    }

    public function testGroqStreamingReturnsEmptyArray(): void
    {
        self::assertSame([], $this->translator->translate('groq', 'openai/gpt-oss-120b', true, $this->schema()));
    }

    public function testOpenAiJsonSchemaClusterStrictWhenModelDocumented(): void
    {
        $result = $this->translator->translate('groq', 'openai/gpt-oss-120b', false, $this->schema());

        self::assertSame([
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'sort_classification',
                    'schema' => ['type' => 'object', 'properties' => ['topic' => ['type' => 'string']], 'required' => ['topic']],
                    'strict' => true,
                ],
            ],
        ], $result);
    }

    public function testOpenAiJsonSchemaClusterNonStrictForUndocumentedModel(): void
    {
        $result = $this->translator->translate('mistral', 'mistral-large-latest', false, $this->schema());

        self::assertFalse($result['response_format']['json_schema']['strict']);
    }

    public function testCallerRequestingNonStrictIsHonoured(): void
    {
        $result = $this->translator->translate('groq', 'openai/gpt-oss-120b', false, $this->schema(strict: false));

        self::assertFalse($result['response_format']['json_schema']['strict']);
    }

    public function testOpenAiResponsesApiUsesTextFormatNesting(): void
    {
        $result = $this->translator->translate('openai', 'gpt-5', false, $this->schema());

        self::assertArrayHasKey('text', $result);
        self::assertArrayNotHasKey('response_format', $result);
        self::assertSame('json_schema', $result['text']['format']['type']);
        self::assertSame('sort_classification', $result['text']['format']['name']);
    }

    public function testGoogleUsesGenerationConfigResponseJsonSchema(): void
    {
        $result = $this->translator->translate('google', 'gemini-2.5-flash', false, $this->schema());

        self::assertSame('application/json', $result['generationConfig']['responseMimeType']);
        self::assertSame(['type' => 'object', 'properties' => ['topic' => ['type' => 'string']], 'required' => ['topic']], $result['generationConfig']['responseJsonSchema']);
        // Google requires the legacy field to be absent when this one is set.
        self::assertArrayNotHasKey('responseSchema', $result['generationConfig']);
    }

    public function testGoogleSchemaIsNormalizedIntoTheSupportedKeywordSubset(): void
    {
        $nullable = new StructuredOutputSchema(
            name: 'sort_classification',
            schema: [
                'type' => 'object',
                'properties' => ['BMEDIA' => ['type' => ['string', 'null'], 'enum' => ['image', null]]],
                'required' => ['BMEDIA'],
            ],
        );

        $result = $this->translator->translate('google', 'gemini-2.5-flash', false, $nullable);

        self::assertSame(
            ['anyOf' => [['type' => 'string', 'enum' => ['image']], ['type' => 'null']]],
            $result['generationConfig']['responseJsonSchema']['properties']['BMEDIA'],
        );
    }

    public function testOtherDialectsKeepUnionTypesVerbatim(): void
    {
        $nullable = new StructuredOutputSchema(
            name: 'sort_classification',
            schema: ['type' => 'object', 'properties' => ['BMEDIA' => ['type' => ['string', 'null']]]],
        );

        $result = $this->translator->translate('groq', 'openai/gpt-oss-120b', false, $nullable);

        self::assertSame(
            ['type' => ['string', 'null']],
            $result['response_format']['json_schema']['schema']['properties']['BMEDIA'],
        );
    }

    public function testOllamaUsesFormatField(): void
    {
        $result = $this->translator->translate('ollama', 'gpt-oss:20b', false, $this->schema());

        self::assertSame(['type' => 'object', 'properties' => ['topic' => ['type' => 'string']], 'required' => ['topic']], $result['format']);
    }

    public function testAnthropicForcesASingleToolCall(): void
    {
        $result = $this->translator->translate('anthropic', 'claude-sonnet-5', false, $this->schema());

        self::assertCount(1, $result['tools']);
        self::assertSame('sort_classification', $result['tools'][0]['name']);
        self::assertSame(['type' => 'object', 'properties' => ['topic' => ['type' => 'string']], 'required' => ['topic']], $result['tools'][0]['input_schema']);
        self::assertSame(['type' => 'tool', 'name' => 'sort_classification'], $result['tool_choice']);
    }
}
