<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\Stage\CheckReport;
use App\Service\Research\Run\Stage\DraftSentences;
use PHPUnit\Framework\TestCase;

final class CheckReportTest extends TestCase
{
    private const COUNTS = ['statements' => 2, 'supported' => 1, 'removed' => 1];

    public function testARemovedFirstSentenceLeavesNoLeadingSpace(): void
    {
        $report = $this->removing("# Title\n\nThe right is old. [e1] It applies to controllers. [e2]", 'The right is old. [e1]');

        self::assertStringStartsWith("# Title\n\nIt applies to controllers. [e2]", $report);
    }

    public function testARemovedFirstSentenceOfAListItemKeepsTheMarker(): void
    {
        $report = $this->removing("- The date is open. [e1] The code is 201. [e2]", 'The date is open. [e1]');

        self::assertStringStartsWith('- The code is 201. [e2]', $report);
    }

    public function testATableThatLostEveryRowIsDropped(): void
    {
        $draft = "## Cases\n\n| Decision | Date |\n|---|---|\n| CJEU C-1/24 [e1] | 2024 |\n\n## Next\n\nText. [e2]";

        $report = $this->removing($draft, '| CJEU C-1/24 [e1] | 2024 |');

        self::assertStringNotContainsString('| Decision |', $report);
        self::assertStringContainsString("## Cases\n\n## Next", $report);
    }

    public function testATableWithRowsLeftIsKept(): void
    {
        $draft = "| Decision | Date |\n|---|---|\n| A [e1] | 2024 |\n| B [e2] | 2025 |";

        $report = $this->removing($draft, '| A [e1] | 2024 |');

        self::assertStringContainsString("| Decision | Date |\n|---|---|\n| B [e2] | 2025 |", $report);
    }

    public function testAQuoteThatEndsInAnEllipsisDoesNotSplitTheSentence(): void
    {
        $sentences = DraftSentences::split('These included claims (e.g., "It is defined in RFC 9110... ," and "It comes from the IETF") without citations. [e1]');

        self::assertCount(1, $sentences);
    }

    public function testABareSentenceHasNoSpaceBeforePunctuation(): void
    {
        self::assertSame('It was published in June 2014, and RFC 9110 is current.', DraftSentences::bare('It was published in June 2014 [e2], and RFC 9110 is current [e3].'));
    }

    private function removing(string $draft, string $sentence): string
    {
        $asked = [['id' => 'c1', 'sentence' => $sentence, 'bare' => DraftSentences::bare($sentence), 'passages' => '']];

        return CheckReport::apply($draft, [], ['c1' => 'unsupported'], $asked, 'en', self::COUNTS, false);
    }
}
