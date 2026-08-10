<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\AnthropicJsonSchemaNormalizer;
use PHPUnit\Framework\TestCase;

final class AnthropicJsonSchemaNormalizerTest extends TestCase
{
    private AnthropicJsonSchemaNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new AnthropicJsonSchemaNormalizer();
    }

    public function testEmptyPropertiesSurviveJsonRoundTripAsObject(): void
    {
        // Mimic what json_decode(..., true) does to Claude Code's CronList /
        // TaskList tools: {"properties":{}} becomes an empty PHP array.
        /** @var array{tools: list<array{input_schema: array{properties: array<mixed>}}>} $decoded */
        $decoded = json_decode(
            '{"tools":[{"name":"CronList","input_schema":{"type":"object","properties":{},"additionalProperties":false}}]}',
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertSame([], $decoded['tools'][0]['input_schema']['properties']);

        $normalized = $this->normalizer->normalizeRequestBody($decoded);
        $encoded = json_encode($normalized, \JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"properties":{}', $encoded);
        self::assertStringNotContainsString('"properties":[]', $encoded);
    }

    public function testNestedEmptyPropertiesAlsoBecomeObjects(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'files' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];

        $encoded = json_encode($this->normalizer->normalizeSchema($schema), \JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"properties":{}', $encoded);
        self::assertStringNotContainsString('"properties":[]', $encoded);
    }

    public function testEmptyRequiredArrayStaysAJsonArray(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['q' => ['type' => 'string']],
            'required' => [],
        ];

        $encoded = json_encode($this->normalizer->normalizeSchema($schema), \JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"required":[]', $encoded);
    }

    public function testEmptyAdditionalPropertiesSurviveAsObject(): void
    {
        // TaskCreate's metadata field: additionalProperties: {}
        $schema = [
            'type' => 'object',
            'properties' => [
                'metadata' => [
                    'type' => 'object',
                    'propertyNames' => ['type' => 'string'],
                    'additionalProperties' => [],
                ],
            ],
            'required' => ['subject'],
            'additionalProperties' => false,
        ];

        $encoded = json_encode($this->normalizer->normalizeSchema($schema), \JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"additionalProperties":{}', $encoded);
        self::assertStringNotContainsString('"additionalProperties":[]', $encoded);
        self::assertStringContainsString('"additionalProperties":false', $encoded);
    }

    public function testCustomWrappedToolSchemasAreNormalized(): void
    {
        $body = [
            'tools' => [[
                'type' => 'custom',
                'name' => 'CronList',
                'custom' => [
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => [],
                    ],
                ],
            ]],
        ];

        $encoded = json_encode($this->normalizer->normalizeRequestBody($body), \JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"properties":{}', $encoded);
    }

    public function testEmptyChildPropertySchemaBecomesObject(): void
    {
        // {"properties":{"extra":{}}} → property "extra" decodes as [].
        $schema = [
            'type' => 'object',
            'properties' => [
                'extra' => [],
            ],
        ];

        $encoded = json_encode($this->normalizer->normalizeSchema($schema), \JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"extra":{}', $encoded);
        self::assertStringNotContainsString('"extra":[]', $encoded);
    }

    public function testEmptyItemsSchemaBecomesObject(): void
    {
        $schema = [
            'type' => 'array',
            'items' => [],
        ];

        $encoded = json_encode($this->normalizer->normalizeSchema($schema), \JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"items":{}', $encoded);
        self::assertStringNotContainsString('"items":[]', $encoded);
    }

    public function testEmptyRootSchemaBecomesObject(): void
    {
        $encoded = json_encode($this->normalizer->normalizeSchema([]), \JSON_THROW_ON_ERROR);

        self::assertSame('{}', $encoded);
    }
}
