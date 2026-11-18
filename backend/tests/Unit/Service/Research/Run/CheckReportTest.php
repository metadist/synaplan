<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\Stage\CheckReport;
use App\Service\Research\Run\Stage\CitationMarks;
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

    public function testARejectedSentenceIsFoundAgainWhateverSpaceStoodBeforeItsMarker(): void
    {
        $draft = 'The fee is 99 EUR.  [e1] The warranty is two years. [e2]';

        self::assertSame(['The fee is 99 EUR.  [e1]', 'The warranty is two years. [e2]'], DraftSentences::split($draft));
        self::assertStringStartsWith('The warranty is two years. [e2]', $this->removing($draft, DraftSentences::split($draft)[0]));
    }

    public function testTheEmphasisAroundASentenceIsRemovedWithIt(): void
    {
        $draft = "**The fee is 99 EUR. [e1]**\n\nThe warranty is two years. [e2]";

        self::assertSame(['**The fee is 99 EUR. [e1]**', 'The warranty is two years. [e2]'], DraftSentences::split($draft));
        self::assertStringStartsWith('The warranty is two years. [e2]', $this->removing($draft, DraftSentences::split($draft)[0]));
    }

    public function testASmallLetterAfterAMarkerStartsANewSentence(): void
    {
        $sentences = DraftSentences::split('The fee is 12 EUR. [e1] iPhone costs 99 EUR. [e2]');

        self::assertSame(['The fee is 12 EUR. [e1]', 'iPhone costs 99 EUR. [e2]'], $sentences);
    }

    public function testABracketInsideABracketDoesNotSplitTheSentence(): void
    {
        $line = 'The fee (set in 2024 (under the new law). Six weeks after approval) is 90 EUR. [e1]';

        self::assertSame([$line], DraftSentences::split($line));
    }

    public function testARowOfATableWithoutOuterPipesIsOneStatement(): void
    {
        $row = 'Example | 2019. Six weeks at the top. [e1]';

        $sentences = DraftSentences::split("Song | Notes\n--- | ---\n".$row."\n\nA | B is no row here. It goes on. [e2]");

        self::assertSame([$row, 'A | B is no row here.', 'It goes on. [e2]'], $sentences);
    }

    public function testATableWithoutOuterPipesThatLostEveryRowIsDropped(): void
    {
        $row = 'Example | 2019. Six weeks at the top. [e1]';

        $report = $this->removing("## Songs\n\nSong | Notes\n--- | ---\n".$row."\n\nText. [e2]", $row);

        self::assertStringStartsWith("## Songs\n\nText. [e2]", $report);
    }

    public function testTheEmphasisThatClosesBeforeTheNextSentenceIsRemovedWithItsOwn(): void
    {
        $draft = '**The fee is 99 EUR. [e1]** The warranty is two years. [e2]';

        self::assertSame(['**The fee is 99 EUR. [e1]**', 'The warranty is two years. [e2]'], DraftSentences::split($draft));
        self::assertStringStartsWith('The warranty is two years. [e2]', $this->removing($draft, DraftSentences::split($draft)[0]));
    }

    public function testEmphasisThatOpensAfterAMarkerBelongsToTheNextSentence(): void
    {
        $sentences = DraftSentences::split('The fee is 99 EUR. [e1] **The warranty is two years.** [e2]');

        self::assertSame(['The fee is 99 EUR. [e1]', '**The warranty is two years.** [e2]'], $sentences);
    }

    public function testAMarkerInsideAnOpenBracketDoesNotSplitTheSentence(): void
    {
        $line = 'The fee (introduced in 2024. [e1] Extended in 2025) is 90 EUR. [e2]';

        self::assertSame([$line], DraftSentences::split($line));
    }

    public function testAListItemAfterATableIsNotARowOfIt(): void
    {
        $sentences = DraftSentences::split("Song | Notes\n--- | ---\nA | B. Still B. [e1]\n- Cost | one. Two more. [e2]");

        self::assertSame(['A | B. Still B. [e1]', 'Cost | one.', 'Two more. [e2]'], $sentences);
    }

    public function testAnEmptiedTableIsDroppedBeforeAHeadingThatHoldsAPipe(): void
    {
        $row = 'A | B. Still B. [e1]';

        $report = $this->removing("Song | Notes\n--- | ---\n".$row."\n## Next | step\n\nText. [e2]", $row);

        self::assertStringStartsWith("## Next | step\n\nText. [e2]", $report);
    }

    public function testARowCitedOnlyByAPlanPointIsOneStatement(): void
    {
        $plan = CitationMarks::planBares("Product | Notes\n--- | ---\nProduct | Talks remain unspecified. Further talks continue. [p1]");

        self::assertSame(['product | talks remain unspecified. further talks continue.'], array_keys($plan));
    }

    public function testEmphasisThatOpensRightAfterAMarkerBelongsToTheNextSentence(): void
    {
        $draft = 'The fee is 99 EUR. [e1]**The warranty is 5 years. [e2]**';

        self::assertSame(['The fee is 99 EUR. [e1]', '**The warranty is 5 years. [e2]**'], DraftSentences::split($draft));
        self::assertStringStartsWith('**The warranty is 5 years. [e2]**', $this->removing($draft, 'The fee is 99 EUR. [e1]'));
        self::assertStringStartsWith('The fee is 99 EUR. [e1]', $this->removing($draft, '**The warranty is 5 years. [e2]**'));
    }

    public function testARemovedSentenceIsNotCutOutOfALongerNumber(): void
    {
        $report = $this->removing('The index is 12.5. The fee is 12.5.', '5.');

        self::assertStringStartsWith('The index is 12.5. The fee is 12.5.', $report);
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
