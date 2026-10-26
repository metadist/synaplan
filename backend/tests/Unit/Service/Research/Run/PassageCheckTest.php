<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\Ledger\PassageSplitter as RunSplitter;
use App\Service\Research\Run\Ledger\PassageText;
use App\Service\Research\Run\Ledger\PassageVerifier;
use App\Service\Research\Spike\PassageSplitter as SpikeSplitter;
use PHPUnit\Framework\TestCase;

final class PassageCheckTest extends TestCase
{
    public function testTheSameTextKeepsTheSameIdsAsTheSpike(): void
    {
        $text = "First paragraph.\n\n- one item\n\n| col | val |\n| --- | --- |\n\n".str_repeat('Sentence one. ', 50);
        $spike = (new SpikeSplitter())->split($text);
        $run = (new RunSplitter())->split($text, 600);

        self::assertSame(
            array_map(static fn ($passage): string => $passage->id.' '.$passage->text, $spike),
            array_map(static fn ($passage): string => $passage->id.' '.$passage->text, $run->passages),
        );
    }

    public function testAnInventedPassageIsRejectedAndAForeignQuoteIsRemoved(): void
    {
        $map = (new RunSplitter())->split("The price is 1.500 euro.\n\nThe warranty is two years.", 600);
        $verifier = new PassageVerifier();

        self::assertFalse($verifier->passagesExist(['§9'], $map));
        self::assertTrue($verifier->passagesExist(['§1'], $map));
        self::assertNotNull($verifier->quoteInPassage('“1,500 euro”', '§1', $map));
        self::assertNull($verifier->quoteInPassage('two years', '§1', $map));
        self::assertNull($verifier->quoteInPassage('two years', '§9', $map));
    }

    public function testNumbersAndHyphenationMatchAcrossWritings(): void
    {
        $map = (new RunSplitter())->split("The price is 12.40 EUR.\n\nA well-\nknown supplier sold 1,234.5 units.", 600);
        $verifier = new PassageVerifier();

        self::assertNotNull($verifier->quoteInPassage('12,40 EUR', '§1', $map));
        self::assertNotNull($verifier->quoteInPassage('well-known supplier', '§2', $map));
        self::assertTrue($verifier->numbersSupported('The price is 12,40 EUR.', ['The price is 12.40 EUR.']));
        self::assertTrue($verifier->numbersSupported('They sold 1.234,5 units.', ['sold 1,234.5 units']));
        self::assertFalse($verifier->numbersSupported('The price is 99 EUR.', ['The price is 12.40 EUR.']));
        self::assertTrue($verifier->numbersSupported('No numbers here.', ['Anything.']));
    }

    public function testDatesCompareEqualAcrossWritings(): void
    {
        self::assertSame(['2024-03-12'], PassageText::datesIn('12 March 2024'));
        self::assertSame(['2024-03-12'], PassageText::datesIn('12.03.2024'));
        self::assertSame(['2024-03-12'], PassageText::datesIn('12. März 2024'));
        self::assertSame(['2024-03-12'], PassageText::datesIn('12 mars 2024'));
        self::assertSame(['2024-03-12'], PassageText::datesIn('12 marzo 2024'));
        self::assertSame(['2024-03-12'], PassageText::datesIn('12 Mart 2024'));
        self::assertSame([], PassageText::datesIn('Regulation (EU) 2024/1689'));
        self::assertSame(['12.40'], PassageText::numbersIn(PassageText::withoutDates('The price is 12.40 EUR on 12 March 2024.')));
    }
}
