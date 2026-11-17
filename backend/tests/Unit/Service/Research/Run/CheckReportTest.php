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
        $report = $this->removing('- The date is open. [e1] The code is 201. [e2]', 'The date is open. [e1]');

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

    public function testATableRowIsOneStatementWhateverFullStopsItsCellsHold(): void
    {
        $row = '| "Run This Town" (feat. Rihanna) | Peaked at No. 2. Won two awards. [e1] |';

        self::assertSame([$row], DraftSentences::split("| Song | Notes |\n|---|---|\n".$row));
    }

    public function testAnAbbreviationInsideABracketDoesNotSplitTheSentence(): void
    {
        $sentences = DraftSentences::split('- "Run This Town" (feat. Rihanna) stayed (approx. Five weeks) in the top 10. [e1] It won two awards. [e2]');

        self::assertSame(['"Run This Town" (feat. Rihanna) stayed (approx. Five weeks) in the top 10. [e1]', 'It won two awards. [e2]'], $sentences);
    }

    public function testAMarkerAfterTheFullStopBelongsToTheSentenceBeforeIt(): void
    {
        $sentences = DraftSentences::split('The fee is 90 EUR. [e2] Income must be 3,480 EUR. [e1][e2] It is due in the top 10. [e3] Nothing else applies.');

        self::assertSame([
            'The fee is 90 EUR. [e2]',
            'Income must be 3,480 EUR. [e1][e2]',
            'It is due in the top 10. [e3]',
            'Nothing else applies.',
        ], $sentences);
    }

    public function testASentenceThatGoesOnAfterAMarkerKeepsItsSpacing(): void
    {
        $line = 'It was published in June 2014. [e2], and RFC 9110 is current. [e3]';

        self::assertSame([$line], DraftSentences::split($line));
    }

    public function testFeatDoesNotEndASentence(): void
    {
        self::assertCount(1, DraftSentences::split('"Holy Grail" feat. Justin Timberlake peaked at No. 4. [e1]'));
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
