<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\AnthropicContentText;
use PHPUnit\Framework\TestCase;

final class AnthropicContentTextTest extends TestCase
{
    public function testToolResultObjectBecomesEmpty(): void
    {
        $payload = [
            'type' => 'tool_result',
            'tool_use_id' => 'toolu_1',
            'content' => '# SKILL.md\nrun this',
        ];

        self::assertSame('', AnthropicContentText::humanText($payload));
        self::assertSame('', AnthropicContentText::collapseStored((string) json_encode($payload)));
    }

    public function testMixedBlocksKeepTheTypedTextOnly(): void
    {
        $payload = [
            ['type' => 'text', 'text' => 'Make 3 slides about Q3'],
            ['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => '{"raw":true}'],
        ];

        self::assertSame('Make 3 slides about Q3', AnthropicContentText::humanText($payload));
    }

    public function testOrdinaryProseAndUserJsonStayIntact(): void
    {
        self::assertSame('Hello there', AnthropicContentText::humanText('Hello there'));
        self::assertNull(AnthropicContentText::collapseStored('{"foo":1}'));
        self::assertSame('{"foo":1}', AnthropicContentText::humanText('{"foo":1}'));
    }
}
