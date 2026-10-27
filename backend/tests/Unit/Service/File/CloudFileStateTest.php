<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Entity\File;
use App\Service\File\CloudFileState;
use PHPUnit\Framework\TestCase;

final class CloudFileStateTest extends TestCase
{
    public function testEachStateAndAQuotedVersionStayExact(): void
    {
        self::assertSame(CloudFileState::NONE, CloudFileState::of(null, '"v1"'));

        $searchable = $this->file(File::VECTOR_STATE_VECTORIZED, '"v1"');
        self::assertSame(CloudFileState::SEARCHABLE, CloudFileState::of($searchable, '"v1"'));
        self::assertSame(CloudFileState::CHANGED, CloudFileState::of($searchable, 'v1'));

        $kept = $this->file(File::VECTOR_STATE_VECTORIZED, '"v1"');
        $kept->setKeepSynced(true);
        self::assertSame(CloudFileState::UPDATING, CloudFileState::of($kept, '"v2"'));

        $adding = $this->file(File::VECTOR_STATE_PENDING, '"v1"');
        self::assertSame(CloudFileState::ADDING, CloudFileState::of($adding, '"v1"'));

        $failed = $this->file(File::VECTOR_STATE_FAILED, '"v1"');
        self::assertSame(CloudFileState::FAILED, CloudFileState::of($failed, '"v1"'));

        $gone = $this->file(File::VECTOR_STATE_VECTORIZED, '"v1"');
        $gone->setSourceDeletedAt(time());
        self::assertSame(CloudFileState::NONE, CloudFileState::of($gone, '"v1"'));

        $long = str_repeat('a', 255);
        $wide = $this->file(File::VECTOR_STATE_VECTORIZED, $long);
        self::assertSame(CloudFileState::SEARCHABLE, CloudFileState::of($wide, $long));
    }

    private function file(string $vectorState, string $version): File
    {
        $file = new File();
        $file->setVectorState($vectorState);
        $file->setSourceEtag($version);

        return $file;
    }
}
