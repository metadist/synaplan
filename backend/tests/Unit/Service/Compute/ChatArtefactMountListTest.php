<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Compute;

use App\Service\Compute\ChatArtefactMountList;
use PHPUnit\Framework\TestCase;

final class ChatArtefactMountListTest extends TestCase
{
    public function testTwoPriorRunsMountOnlyTheNamedFile(): void
    {
        $list = new ChatArtefactMountList();
        $chatFiles = [
            ['id' => 9, 'name' => 'chart.png'],
            ['id' => 8, 'name' => 'summary.xlsx'],
        ];

        $resolved = $list->resolve([], $chatFiles, ['summary.xlsx'], 'chart the top ten from summary.xlsx');

        self::assertSame([], $resolved['missing']);
        self::assertSame([8], $resolved['ids']);
    }

    public function testAMissingNameIsReportedAndNothingElseIsMounted(): void
    {
        $list = new ChatArtefactMountList();
        $resolved = $list->resolve(
            [3],
            [
                ['id' => 3, 'name' => 'input.csv'],
                ['id' => 8, 'name' => 'summary.xlsx'],
            ],
            ['missing.xlsx'],
            'use missing.xlsx',
        );

        self::assertSame(['missing.xlsx'], $resolved['missing']);
        self::assertSame([3], $resolved['ids']);
    }

    public function testASinglePriorFileIsMountedWhenThePersonPointsAtIt(): void
    {
        $list = new ChatArtefactMountList();
        $resolved = $list->resolve([], [['id' => 8, 'name' => 'summary.xlsx']], [], 'chart the spreadsheet you just made');

        self::assertSame([8], $resolved['ids']);
        self::assertSame([], $resolved['missing']);
    }

    public function testTwoPriorFilesAreNotMountedWithoutAName(): void
    {
        $list = new ChatArtefactMountList();
        $resolved = $list->resolve(
            [],
            [
                ['id' => 9, 'name' => 'chart.png'],
                ['id' => 8, 'name' => 'summary.xlsx'],
            ],
            [],
            'chart the spreadsheet you just made',
        );

        self::assertSame([], $resolved['ids']);
    }
}
