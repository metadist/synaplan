<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Eval;

use App\Service\Research\Eval\CorpusFacts;
use App\Service\Research\Eval\CorpusItem;
use App\Service\Research\Eval\Scorer;
use PHPUnit\Framework\TestCase;

final class ScorerTest extends TestCase
{
    private Scorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new Scorer();
    }

    public function testAMatchStringIsFoundIgnoringCase(): void
    {
        $this->assertTrue($this->scorer->containsAll('See Art. 53 for the duty.', ['art. 53']));
        $this->assertFalse($this->scorer->containsAll('No article here.', ['Art. 53']));
    }

    public function testThousandsSeparatorsMatchAPlainNumber(): void
    {
        $this->assertTrue($this->scorer->containsAll('The price is 1,500 euro.', ['1500']));
        $this->assertTrue($this->scorer->containsAll('Der Preis ist 1.500 EUR.', ['1500']));
        $this->assertTrue($this->scorer->containsAll('Speed 299,792,458 m/s.', ['299792458']));
        $this->assertSame('3,5', $this->scorer->normalise('3,5'));
        $this->assertFalse($this->scorer->containsAll('See section 170.', ['17']));
    }

    public function testFactRecallIsTheShareOfMatchedGoldRows(): void
    {
        $item = $this->item([['id' => 'g1', 'assertion' => 'A', 'kind' => 'fact', 'match' => ['ZEBRA-41', '72']]]);

        $hit = $this->scorer->score($item, 'Nummer ZEBRA-41, Frist 72 Stunden.');
        $miss = $this->scorer->score($item, 'Nur die Nummer ZEBRA-41.');

        $this->assertSame(1.0, $hit['recall']);
        $this->assertSame(0.0, $miss['recall']);
    }

    public function testATrapAbstainsWhenTheForbiddenPhraseIsAbsent(): void
    {
        $item = $this->item([], ['Synaplan-Verordnung verpflichtet']);

        $abstained = $this->scorer->score($item, 'Diese Verordnung gibt es nicht.');
        $claimed = $this->scorer->score($item, 'Die Synaplan-Verordnung verpflichtet Anbieter.');

        $this->assertTrue($abstained['abstained']);
        $this->assertFalse($claimed['abstained']);
        $this->assertNull($abstained['recall']);
    }

    /**
     * @param list<array{id: string, assertion: string, kind: string, match: list<string>}> $gold
     * @param list<string>                                                                  $forbidden
     */
    private function item(array $gold, array $forbidden = []): CorpusItem
    {
        return new CorpusItem(
            't',
            'trap',
            'de',
            'Frage',
            'standard',
            true,
            false,
            new CorpusFacts($gold, [], $forbidden),
            true,
            null,
        );
    }
}
