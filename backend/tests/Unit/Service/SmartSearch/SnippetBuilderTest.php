<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SmartSearch;

use App\Service\SmartSearch\SnippetBuilder;
use PHPUnit\Framework\TestCase;

final class SnippetBuilderTest extends TestCase
{
    public function testCentersOnTheFirstMatch(): void
    {
        $body = str_repeat('filler ', 60).'the invoice total is due in March '.str_repeat('tail ', 60);

        $snippet = SnippetBuilder::build($body, ['invoice']);

        self::assertNotNull($snippet);
        self::assertStringStartsWith('…', $snippet);
        self::assertStringEndsWith('…', $snippet);
        self::assertStringContainsString('invoice total', $snippet);
    }

    public function testShortBodyIsReturnedWhole(): void
    {
        self::assertSame('Quarterly plan', SnippetBuilder::build("  Quarterly\n plan ", ['plan']));
    }

    public function testNoMatchStartsAtTheBeginning(): void
    {
        $snippet = SnippetBuilder::build(str_repeat('word ', 80), ['missing']);

        self::assertNotNull($snippet);
        self::assertStringStartsWith('word', $snippet);
    }

    public function testEmptyBodyHasNoSnippet(): void
    {
        self::assertNull(SnippetBuilder::build('   ', ['x']));
    }
}
