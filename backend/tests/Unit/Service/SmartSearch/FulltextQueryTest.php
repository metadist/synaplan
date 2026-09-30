<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SmartSearch;

use App\Service\SmartSearch\Index\FulltextQuery;
use PHPUnit\Framework\TestCase;

final class FulltextQueryTest extends TestCase
{
    public function testWordsBecomeOptionalPrefixTerms(): void
    {
        $query = new FulltextQuery('Rechnung März PDF');

        self::assertSame(['rechnung', 'märz', 'pdf'], $query->terms);
        self::assertSame('rechnung* märz* pdf*', $query->booleanExpression());
    }

    public function testBooleanOperatorsAreStripped(): void
    {
        $query = new FulltextQuery('+invoice -"draft" (report)* ~x @2');

        self::assertSame('invoice* draft* report*', $query->booleanExpression());
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
