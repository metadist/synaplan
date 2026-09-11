<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Context;

use App\Service\Context\AttachmentDigest;
use App\Service\Context\TokenEstimator;
use PHPUnit\Framework\TestCase;

final class AttachmentDigestTest extends TestCase
{
    private AttachmentDigest $digest;

    protected function setUp(): void
    {
        $this->digest = new AttachmentDigest(new TokenEstimator());
    }

    public function testSmallTextPassesThroughUnchanged(): void
    {
        self::assertSame('short file text', $this->digest->forRouting("short file text\n", 1800, 'txt'));
    }

    public function testLargeSpreadsheetBecomesStructuredDigestWithinBudget(): void
    {
        $rows = [];
        for ($i = 1; $i <= 3000; ++$i) {
            $rows[] = sprintf('| %d | 2025-%02d-01 | Region-%d | %d.00 |', $i, $i % 12 + 1, $i % 7, $i * 91);
        }
        $text = "## Sheet: Sales 2025\n\n| ID | Date | Region | Revenue |\n| --- | --- | --- | --- |\n".implode("\n", $rows)
            ."\n\n## Sheet: Regions\n\n| Code | Name |\n| --- | --- |\n| R1 | North |\n| R2 | South |\nGRAND TOTAL 1234567";

        $digest = $this->digest->forRouting($text, 1800, 'xlsx');

        self::assertLessThanOrEqual(2000, mb_strlen($digest), 'digest stays near the budget');
        self::assertStringContainsString('spreadsheet', $digest);
        self::assertStringContainsString('Sections: Sheet: Sales 2025 | Sheet: Regions', $digest);
        self::assertStringContainsString('First table columns: ID, Date, Region, Revenue', $digest);
        self::assertStringContainsString('--- BEGINNING ---', $digest);
        self::assertStringContainsString('## Sheet: Sales 2025', $digest);
        self::assertStringContainsString('--- END ---', $digest);
        self::assertStringContainsString('GRAND TOTAL 1234567', $digest, 'the tail (totals) is visible to the router');
        self::assertStringNotContainsString('| 1500 |', $digest, 'the bulk of the rows is not in the digest');
    }

    public function testKindFallsBackToContentShapeWhenTypeIsUnknown(): void
    {
        $prose = str_repeat("A long paragraph of ordinary prose without tables.\n", 200);

        $digest = $this->digest->forRouting($prose, 600, null);

        self::assertStringContainsString('text content', $digest);
        self::assertLessThanOrEqual(800, mb_strlen($digest));
    }
}
