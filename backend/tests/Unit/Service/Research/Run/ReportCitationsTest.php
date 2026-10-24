<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\Stage\ReportCitations;
use PHPUnit\Framework\TestCase;

final class ReportCitationsTest extends TestCase
{
    public function testKnownCitationsStayAndUnknownOnesGo(): void
    {
        $cited = ReportCitations::clean("Price 12 EUR [e1][e9].\n\nDelivery in a day [e7].\n\nIntro without a fact.", ['e1', 'e2']);

        self::assertSame("Price 12 EUR [e1].\n\nDelivery in a day.\n\nIntro without a fact.", $cited['report']);
        self::assertSame(2, $cited['statements']);
        self::assertSame(1, $cited['supported']);
    }

    public function testACommaListIsOneStatement(): void
    {
        $cited = ReportCitations::clean('Both agree [e1, e2].', ['e1', 'e2']);

        self::assertSame('Both agree [e1][e2].', $cited['report']);
        self::assertSame(1, $cited['statements']);
        self::assertSame(1, $cited['supported']);
    }
}
