<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\Ledger\EvidenceLedger;
use App\Service\Research\Run\Ledger\Passage;
use App\Service\Research\Run\Ledger\PassageMap;
use App\Service\Research\Run\Ledger\PassageVerifier;
use App\Service\Research\Run\Ledger\Source;
use App\Service\Research\Run\Stage\AcceptedEvidence;
use PHPUnit\Framework\TestCase;

final class AcceptedEvidenceTest extends TestCase
{
    public function testABareNumberCitesTheNumberedPassageAndPoint(): void
    {
        $cards = $this->accept([
            'point' => '1',
            'fact' => 'The unit price is 12.40 EUR.',
            'passages' => ['1'],
            'quote' => null,
            'confidence' => 'high',
        ]);

        self::assertCount(1, $cards);
        self::assertSame('p1', $cards[0]->pointId);
        self::assertSame(['§1'], $cards[0]->passages);
    }

    public function testAnInventedPassageIdIsNotMatchedByHuntingThePage(): void
    {
        $cards = $this->accept([
            'point' => 'p1',
            'fact' => 'The unit price is 12.40 EUR.',
            'passages' => ['§99'],
            'quote' => ['text' => '12.40 EUR', 'passage' => '§99'],
            'confidence' => 'medium',
        ]);

        self::assertSame([], $cards);
    }

    public function testAFactWithNoRealPassageIsDropped(): void
    {
        $cards = $this->accept([
            'point' => 'p1',
            'fact' => 'The fee is waived.',
            'passages' => ['§99'],
            'quote' => null,
            'confidence' => 'low',
        ]);

        self::assertSame([], $cards);
    }

    /**
     * @param array<string, mixed> $finding
     *
     * @return list<\App\Service\Research\Run\Ledger\EvidenceCard>
     */
    private function accept(array $finding): array
    {
        $ledger = new EvidenceLedger();
        $ledger->addPoint('Unit price');
        $ledger->addSource(Source::web('https://maker.example/price', 'Price list'));
        $map = new PassageMap([new Passage('§1', 'The unit price is 12.40 EUR per unit.')]);

        return (new AcceptedEvidence(new PassageVerifier()))->accept('s1', [
            'findings' => [$finding],
        ], $map, [], $ledger)['cards'];
    }
}
