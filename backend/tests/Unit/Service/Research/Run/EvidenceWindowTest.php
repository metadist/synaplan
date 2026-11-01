<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\Ledger\EvidenceCard;
use App\Service\Research\Run\Ledger\EvidenceLedger;
use App\Service\Research\Run\Ledger\QuoteMatch;
use App\Service\Research\Run\Ledger\ResearchBrief;
use App\Service\Research\Run\Ledger\Source;
use App\Service\Research\Run\Stage\EvidenceWindow;
use PHPUnit\Framework\TestCase;

final class EvidenceWindowTest extends TestCase
{
    public function testAPageFromAnotherMonthCannotAnswerToday(): void
    {
        $today = ['from' => '2026-11-01', 'to' => '2026-11-01'];

        self::assertFalse(EvidenceWindow::covers('April 2026', $today));
        self::assertFalse(EvidenceWindow::covers('2025-12-29', $today));
        self::assertFalse(EvidenceWindow::covers('2026', $today));
        self::assertFalse(EvidenceWindow::covers(null, $today));
        self::assertTrue(EvidenceWindow::covers('2026-11-01', $today));
        self::assertTrue(EvidenceWindow::covers('1 November 2026', $today));
        self::assertTrue(EvidenceWindow::covers('April 2026', null));
    }

    public function testAFileStaysAndAQuoteIsRequiredOnlyForQuoteOnly(): void
    {
        $ledger = new EvidenceLedger();
        $ledger->setBrief(new ResearchBrief('News today', 'en', 'report', null, ['from' => '2026-11-01', 'to' => '2026-11-01']));
        $ledger->addPoint('Notes');
        $ledger->addPoint('Energy');
        $file = $ledger->addSource(Source::file(4, 'Notes.pdf'));
        $old = $ledger->addSource(Source::web('https://energy.example/policy', 'Energy', 'April 2026'));
        $ledger->addEvidence(new EvidenceCard('x', $file, 'p1', 'The note says 4.', ['§1'], []));
        $ledger->addEvidence(new EvidenceCard('x', $old, 'p2', 'The target is 42.5 percent.', ['§1'], [new QuoteMatch('42.5 percent', '§1', true)]));

        self::assertSame(['p1'], array_keys(EvidenceWindow::cards($ledger, false)));
        self::assertSame([], EvidenceWindow::cards($ledger, true));
        self::assertSame(['e1'], EvidenceWindow::freshIds($ledger));
    }
}
