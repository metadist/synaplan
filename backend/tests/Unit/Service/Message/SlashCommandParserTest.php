<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\Service\Message\SlashCommandParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SlashCommandParserTest extends TestCase
{
    private SlashCommandParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SlashCommandParser();
    }

    #[DataProvider('bareCommands')]
    public function testBareCommandsNeedArgument(string $text, string $expectedName): void
    {
        $parsed = $this->parser->parse($text);

        self::assertNotNull($parsed);
        self::assertSame($expectedName, $parsed->name);
        self::assertTrue($parsed->requiresArgument());
        self::assertTrue($parsed->isBare());
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function bareCommands(): iterable
    {
        yield 'pic' => ['/pic', 'pic'];
        yield 'pic whitespace' => ['/pic   ', 'pic'];
        yield 'pic at bot' => ['/pic@TestBot', 'pic'];
        yield 'vid' => ['/vid', 'vid'];
        yield 'tts' => ['/tts', 'tts'];
        yield 'search' => ['/search', 'search'];
        yield 'docs' => ['/docs', 'docs'];
    }

    public function testArgumentIsKept(): void
    {
        $parsed = $this->parser->parse('/pic a dog on the beach');

        self::assertNotNull($parsed);
        self::assertSame('pic', $parsed->name);
        self::assertSame('a dog on the beach', $parsed->argument);
        self::assertFalse($parsed->isBare());
    }

    public function testBotSuffixIsStrippedFromCommand(): void
    {
        $parsed = $this->parser->parse('/pic@Synaplan_Bot a red fox');

        self::assertNotNull($parsed);
        self::assertSame('pic', $parsed->name);
        self::assertSame('a red fox', $parsed->argument);
    }

    public function testPlainTextIsNotACommand(): void
    {
        self::assertNull($this->parser->parse('hello there'));
    }
}
