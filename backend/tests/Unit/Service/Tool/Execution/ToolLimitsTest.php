<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool\Execution;

use App\Service\Tool\Execution\ToolLimits;
use PHPUnit\Framework\TestCase;

final class ToolLimitsTest extends TestCase
{
    public function testClampKeepsAShortResult(): void
    {
        self::assertSame('Updated 1 cells', ToolLimits::clamp('Updated 1 cells'));
    }

    public function testClampMarksTheCutWithAnEllipsis(): void
    {
        $clamped = ToolLimits::clamp(str_repeat('A', ToolLimits::RESULT_CHARS + 1000));

        self::assertSame(ToolLimits::RESULT_CHARS + 1, mb_strlen($clamped));
        self::assertSame('…', mb_substr($clamped, -1));
    }
}
