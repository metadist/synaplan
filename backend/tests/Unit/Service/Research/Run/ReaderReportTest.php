<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\Ledger\EvidenceCard;
use App\Service\Research\Run\Ledger\EvidenceLedger;
use App\Service\Research\Run\Ledger\Source;
use App\Service\Research\Run\ReaderReport;
use PHPUnit\Framework\TestCase;

final class ReaderReportTest extends TestCase
{
    public function testTheReaderGetsTheAnswerWithNumberedSourcesAndNoAudit(): void
    {
        $ledger = $this->ledger();
        $checked = "# Visa\n\nThe fee is 90 EUR. [e2] Income must be 3,480 EUR. [e1][e2]\n\n"
            ."## Recommendation\n\n## Not confirmed\n\n- The tax rate.\n\n"
            ."2 of 3 statements are backed by a source. 1 were removed because the check could not confirm them in a source.\n\n"
            .'This project has one model family. Research keeps only sentences a source quote supports. Add a second provider for a checked overview.';

        $reader = ReaderReport::from($checked, $ledger);

        self::assertSame("# Visa\n\nThe fee is 90 EUR. [1] Income must be 3,480 EUR. [1][2]", $reader['markdown']);
        self::assertSame(['Portugal', 'Spain'], array_column($reader['sources'], 'title'));
        self::assertSame('https://pt.example/visa', $reader['sources'][0]['url']);
        self::assertSame('pt.example', $reader['sources'][0]['host']);
        self::assertSame('Fee 90 EUR.', $reader['sources'][0]['description']);
    }

    public function testAnEmptyAnswerShowsTheGapsAndNotARejectedClaim(): void
    {
        $report = "## Summary\n\n## Not confirmed\n\nThe election names are not in a source.\n\n- The fee is 50 EUR. [e1]\n\n"
            ."0 of 1 statements are backed by a source.\n\n"
            .'This project has one model family. Research keeps only sentences a source quote supports. Add a second provider for a checked overview.';

        $reader = ReaderReport::from($report, $this->ledger());

        self::assertStringContainsString('The election names are not in a source.', $reader['markdown']);
        self::assertStringNotContainsString('50 EUR', $reader['markdown']);
        self::assertStringNotContainsString('backed by a source', $reader['markdown']);
        self::assertSame([], $reader['sources']);
    }

    public function testACitationToEvidenceThatDoesNotExistIsDropped(): void
    {
        $reader = ReaderReport::from('Price 12 EUR. [e9]', $this->ledger());

        self::assertSame('Price 12 EUR.', $reader['markdown']);
        self::assertSame([], $reader['sources']);
    }

    public function testAHeadingWithASubsectionStaysAndAnEmptyLastHeadingGoes(): void
    {
        $reader = ReaderReport::from("## Costs\n\n### Portugal\n\nText. [e1]\n\n## Outlook", $this->ledger());

        self::assertSame("## Costs\n\n### Portugal\n\nText. [1]", $reader['markdown']);
    }

    public function testAFileSourceLinksToTheFile(): void
    {
        $ledger = new EvidenceLedger();
        $ledger->addPoint('Terms');
        $file = $ledger->addSource(Source::file(42, 'Contract.pdf'));
        $ledger->addEvidence(new EvidenceCard('', $file, 'p1', 'Notice is 30 days.', ['§1'], []));

        $reader = ReaderReport::from('Notice is 30 days. [e1]', $ledger);

        self::assertSame('/files?file=42', $reader['sources'][0]['url']);
        self::assertSame('file', $reader['sources'][0]['host']);
    }

    private function ledger(): EvidenceLedger
    {
        $ledger = new EvidenceLedger();
        $ledger->addPoint('Costs');
        $spain = $ledger->addSource(Source::web('https://es.example/visa', 'Spain'));
        $portugal = $ledger->addSource(Source::web('https://pt.example/visa', 'Portugal'));
        $ledger->addEvidence(new EvidenceCard('', $spain, 'p1', 'Income 2,763 EUR.', ['§1'], []));
        $ledger->addEvidence(new EvidenceCard('', $portugal, 'p1', 'Fee 90 EUR.', ['§1'], []));

        return $ledger;
    }
}
