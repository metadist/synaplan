<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\File;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the knowledge-file lifecycle fields (hosting-partner
 * CORE-4): external identity (BSOURCEID/BSOURCEETAG) + the explicit stale
 * marker (BSTALE), plus the new "stale" vector state in the vocabulary.
 */
final class FileLifecycleTest extends TestCase
{
    public function testLifecycleDefaults(): void
    {
        $file = new File();

        self::assertNull($file->getSourceId());
        self::assertNull($file->getSourceEtag());
        self::assertFalse($file->isStale());
    }

    public function testSourceIdIsTrimmedAndEmptyBecomesNull(): void
    {
        $file = new File();

        $file->setSourceId('  12345  ');
        self::assertSame('12345', $file->getSourceId());

        $file->setSourceId('   ');
        self::assertNull($file->getSourceId());

        $file->setSourceId(null);
        self::assertNull($file->getSourceId());
    }

    public function testSourceEtagIsTrimmedAndEmptyBecomesNull(): void
    {
        $file = new File();

        $file->setSourceEtag('  a1b2c3  ');
        self::assertSame('a1b2c3', $file->getSourceEtag());

        $file->setSourceEtag('');
        self::assertNull($file->getSourceEtag());
    }

    public function testStaleFlagRoundtrips(): void
    {
        $file = new File();

        $file->setStale(true);
        self::assertTrue($file->isStale());

        $file->setStale(false);
        self::assertFalse($file->isStale());
    }

    public function testStaleIsAcceptedAsAVectorState(): void
    {
        $file = new File();

        self::assertContains(File::VECTOR_STATE_STALE, File::VECTOR_STATES);

        $file->setVectorState(File::VECTOR_STATE_STALE);
        self::assertSame('stale', $file->getVectorState());
    }
}
