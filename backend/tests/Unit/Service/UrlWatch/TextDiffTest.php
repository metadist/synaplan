<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\UrlWatch;

use App\Service\UrlWatch\TextDiff;
use PHPUnit\Framework\TestCase;

final class TextDiffTest extends TestCase
{
    public function testIdenticalTextIsEmpty(): void
    {
        $diff = new TextDiff();

        self::assertSame('', $diff->unified("a\nb\n", "a\nb\n"));
    }

    public function testMarksAddedAndRemovedLines(): void
    {
        $diff = new TextDiff();
        $out = $diff->unified("alpha\nbeta\n", "alpha\ngamma\n");

        self::assertStringContainsString('  alpha', $out);
        self::assertStringContainsString('- beta', $out);
        self::assertStringContainsString('+ gamma', $out);
    }

    public function testTruncatesLongDiff(): void
    {
        $old = implode("\n", array_map(static fn (int $i): string => 'old-'.$i, range(1, 80)));
        $new = implode("\n", array_map(static fn (int $i): string => 'new-'.$i, range(1, 80)));
        $out = (new TextDiff())->unified($old, $new, 20);

        self::assertStringContainsString('(diff truncated)', $out);
        self::assertLessThanOrEqual(21, substr_count($out, "\n") + 1);
    }

    public function testCapsInputSoLcsStaysBounded(): void
    {
        self::assertSame(400, TextDiff::MAX_INPUT_LINES);

        $old = implode("\n", array_map(static fn (int $i): string => 'old-'.$i, range(1, 2000)));
        $new = implode("\n", array_map(static fn (int $i): string => 'new-'.$i, range(1, 2000)));
        $out = (new TextDiff())->unified($old, $new);

        self::assertStringContainsString('(diff truncated)', $out);
        self::assertStringNotContainsString('old-1500', $out);
        self::assertStringNotContainsString('new-1500', $out);
    }
}
