<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Profile;

use App\Service\Profile\ToursSeen;
use PHPUnit\Framework\TestCase;

final class ToursSeenTest extends TestCase
{
    public function testKeepsValidIdsAndDropsDuplicates(): void
    {
        self::assertSame(
            ['apps', 'checklist.dismissed', 'library'],
            ToursSeen::normalize(['apps', 'Checklist.Dismissed', 'library', 'apps'])
        );
    }

    public function testDropsInvalidEntries(): void
    {
        self::assertSame(
            ['ok'],
            ToursSeen::normalize(['ok', '', 42, null, '../etc', 'has space', '-lead', str_repeat('a', 65)])
        );
    }

    public function testNonArrayBecomesEmptyList(): void
    {
        self::assertSame([], ToursSeen::normalize('apps'));
        self::assertSame([], ToursSeen::normalize(null));
    }

    public function testKeepsTheNewestEntriesWhenTooMany(): void
    {
        $ids = array_map(static fn (int $i): string => 'tour'.$i, range(1, ToursSeen::MAX_IDS + 5));
        $normalized = ToursSeen::normalize($ids);

        self::assertCount(ToursSeen::MAX_IDS, $normalized);
        self::assertSame('tour6', $normalized[0]);
        self::assertSame('tour'.(ToursSeen::MAX_IDS + 5), $normalized[ToursSeen::MAX_IDS - 1]);
    }

    public function testReadsFromUserDetails(): void
    {
        self::assertSame(['apps'], ToursSeen::fromDetails([ToursSeen::DETAILS_KEY => ['apps']]));
        self::assertSame([], ToursSeen::fromDetails([]));
    }
}
