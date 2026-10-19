<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Entity\File;
use App\Repository\FileRepository;
use App\Service\File\SourceStaleCheck;
use PHPUnit\Framework\TestCase;

final class SourceStaleCheckTest extends TestCase
{
    public function testKeepsTheCallersOrderAndTheFirstEtagPerId(): void
    {
        $repository = $this->createMock(FileRepository::class);
        $repository->expects(self::once())
            ->method('findByUserSourceIds')
            ->with(7, 'nextcloud', ['b', 'a'])
            ->willReturn(['a' => $this->file('etag-a')]);

        $check = (new SourceStaleCheck($repository))->check(7, 'nextcloud', [
            ['source_id' => ' b '],
            'not-an-item',
            ['source_id' => ''],
            ['source_etag' => 'no-id'],
            ['source_id' => 'a', 'source_etag' => 'etag-a'],
            ['source_id' => 'a', 'source_etag' => 'etag-later'],
        ]);

        self::assertSame(['b', 'a'], array_column($check['results'], 'source_id'));
        self::assertSame(['missing', 'current'], array_column($check['results'], 'status'));
        self::assertSame([null, File::VECTOR_STATE_VECTORIZED], array_column($check['results'], 'vector_state'));
        self::assertSame(['current' => 1, 'stale' => 0, 'missing' => 1], $check['counts']);
    }

    public function testAFileMarkedStaleStaysStaleWithAnUnchangedEtag(): void
    {
        $repository = $this->createStub(FileRepository::class);
        $repository->method('findByUserSourceIds')
            ->willReturn(['a' => $this->file('etag-a')->setStale(true)]);

        $check = (new SourceStaleCheck($repository))->check(7, 'nextcloud', [
            ['source_id' => 'a', 'source_etag' => 'etag-a'],
        ]);

        self::assertSame('stale', $check['results'][0]['status']);
        self::assertSame('etag-a', $check['results'][0]['stored_etag']);
    }

    private function file(string $etag): File
    {
        return (new File())
            ->setSourceEtag($etag)
            ->setVectorState(File::VECTOR_STATE_VECTORIZED);
    }
}
