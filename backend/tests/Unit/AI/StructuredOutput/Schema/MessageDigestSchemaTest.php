<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\Schema\MessageDigestSchema;
use PHPUnit\Framework\TestCase;

final class MessageDigestSchemaTest extends TestCase
{
    public function testBuildNamesTheSchemaMessageDigest(): void
    {
        $schema = MessageDigestSchema::build();

        $this->assertSame('message_digest', $schema->name);
    }

    /**
     * The array root is wrapped in an object property because OpenAI-dialect
     * structured output (and Anthropic tool-forcing) both reject a bare
     * top-level array.
     */
    public function testTopLevelIsAnObjectWrappingTheDigestsArray(): void
    {
        $schema = MessageDigestSchema::build();

        $this->assertSame('object', $schema->schema['type']);
        $this->assertSame(['digests'], $schema->schema['required']);
        $this->assertSame('array', $schema->schema['properties']['digests']['type']);
    }

    public function testEachDigestRequiresTitleAndMessageId(): void
    {
        $schema = MessageDigestSchema::build();
        $item = $schema->schema['properties']['digests']['items'];

        $this->assertSame('string', $item['properties']['title']['type']);
        $this->assertSame('integer', $item['properties']['message_id']['type']);
        $this->assertSame(['title', 'message_id'], $item['required']);
    }

    public function testDefaultsToStrictMode(): void
    {
        $schema = MessageDigestSchema::build();

        $this->assertTrue($schema->strict);
        $this->assertFalse($schema->schema['additionalProperties']);
        $this->assertFalse($schema->schema['properties']['digests']['items']['additionalProperties']);
    }
}
