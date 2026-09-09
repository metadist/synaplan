<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SavedTask;

use App\Service\SavedTask\InboundEmailFilter;
use PHPUnit\Framework\TestCase;

final class InboundEmailFilterTest extends TestCase
{
    public function testAbsentFilterMatchesEverything(): void
    {
        self::assertTrue(InboundEmailFilter::matches(null, 'a@b.com', 'Hi', 'body'));
        self::assertTrue(InboundEmailFilter::matches(['from' => [], 'contains' => []], 'a@b.com', 'Hi', 'body'));
    }

    public function testFromAddressAndDomain(): void
    {
        $filter = ['from' => ['legal@acme.com', '@partner.io'], 'contains' => [], 'match' => 'any'];

        self::assertTrue(InboundEmailFilter::matches($filter, 'legal@acme.com', 'x', ''));
        self::assertTrue(InboundEmailFilter::matches($filter, 'ada@partner.io', 'x', ''));
        self::assertFalse(InboundEmailFilter::matches($filter, 'other@example.com', 'x', ''));
    }

    public function testContainsAnyAndAll(): void
    {
        $any = ['from' => [], 'contains' => ['contract', 'nda'], 'match' => 'any'];
        $all = ['from' => [], 'contains' => ['contract', 'nda'], 'match' => 'all'];

        self::assertTrue(InboundEmailFilter::matches($any, 'a@b.com', 'Please review the NDA', ''));
        self::assertFalse(InboundEmailFilter::matches($all, 'a@b.com', 'Please review the NDA', ''));
        self::assertTrue(InboundEmailFilter::matches($all, 'a@b.com', 'contract and NDA', ''));
    }
}
