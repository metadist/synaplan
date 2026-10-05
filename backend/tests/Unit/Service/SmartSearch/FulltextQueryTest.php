<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SmartSearch;

use App\Service\SmartSearch\Index\FulltextQuery;
use PHPUnit\Framework\TestCase;

final class FulltextQueryTest extends TestCase
{
    public function testShortQueryRequiresEveryWord(): void
    {
        $query = new FulltextQuery('Rechnung März PDF');

        self::assertSame(['rechnung', 'märz', 'pdf'], $query->terms);
        self::assertSame('+rechnung* +märz* +pdf*', $query->booleanExpression());
    }

    public function testLongQueryKeepsWordsOptional(): void
    {
        $query = new FulltextQuery('invoice plumber kitchen march');

        self::assertSame('invoice* plumber* kitchen* march*', $query->booleanExpression());
    }

    public function testBooleanOperatorsAreStripped(): void
    {
        $query = new FulltextQuery('+invoice -"draft" (report)* ~x @2');

        self::assertSame('+invoice* +draft* +report*', $query->booleanExpression());
    }

    public function testFunctionWordsOfEveryLocaleAreDropped(): void
    {
        self::assertSame(['mailserver'], (new FulltextQuery('Wo stelle ich den Mailserver ein?'))->terms);
        self::assertSame(['invoice'], (new FulltextQuery('find the invoice'))->terms);
        self::assertSame(['factura'], (new FulltextQuery('dónde está la factura'))->terms);
    }

    public function testOnlyFunctionWordsFallBackToTitleLike(): void
    {
        $query = new FulltextQuery('The Who');

        self::assertFalse($query->hasTerms());
        self::assertSame('%The Who%', $query->likePattern());
    }

    public function testShortQueryRelaxesToOptionalWords(): void
    {
        self::assertSame('telegram* bot*', (new FulltextQuery('telegram bot'))->relaxedExpression());
        self::assertNull((new FulltextQuery('telegram'))->relaxedExpression());
        self::assertNull((new FulltextQuery('invoice plumber kitchen march'))->relaxedExpression());
    }

    public function testSettingKeysSplitIntoWords(): void
    {
        self::assertSame(['feature', 'iam', 'groups', 'enabled'], (new FulltextQuery('FEATURE_IAM_GROUPS_ENABLED'))->terms);
    }

    public function testShortWordsFallBackToLike(): void
    {
        $query = new FulltextQuery('Q3 50%');

        self::assertFalse($query->hasTerms());
        self::assertSame('%Q3 50\%%', $query->likePattern());
    }

    public function testDuplicatesAndTermCountAreCapped(): void
    {
        $query = new FulltextQuery('one two three four five six seven eight nine ten one');

        self::assertCount(8, $query->terms);
        self::assertSame('one', $query->terms[0]);
    }
}
