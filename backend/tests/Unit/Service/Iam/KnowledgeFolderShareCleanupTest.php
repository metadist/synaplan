<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam;

use App\Repository\FileRepository;
use App\Repository\ShareRepository;
use App\Service\Iam\KnowledgeFolderShareCleanup;
use PHPUnit\Framework\TestCase;

final class KnowledgeFolderShareCleanupTest extends TestCase
{
    public function testDeletesSharesOnceTheFolderIsEmpty(): void
    {
        $files = $this->createMock(FileRepository::class);
        $files->expects(self::once())->method('existsForUserAndGroupKey')->with(7, 'Q3')->willReturn(false);
        $shares = $this->createMock(ShareRepository::class);
        $shares->expects(self::once())->method('deleteByResource')->with('knowledge_folder', '7:Q3');

        (new KnowledgeFolderShareCleanup($files, $shares))->forgetIfEmpty(7, 'Q3');
    }

    public function testKeepsSharesWhileFilesRemain(): void
    {
        $files = $this->createMock(FileRepository::class);
        $files->method('existsForUserAndGroupKey')->willReturn(true);
        $shares = $this->createMock(ShareRepository::class);
        $shares->expects(self::never())->method('deleteByResource');

        (new KnowledgeFolderShareCleanup($files, $shares))->forgetIfEmpty(7, 'Q3');
    }

    public function testIgnoresFilesWithoutAFolder(): void
    {
        $files = $this->createMock(FileRepository::class);
        $files->expects(self::never())->method('existsForUserAndGroupKey');
        $shares = $this->createMock(ShareRepository::class);
        $shares->expects(self::never())->method('deleteByResource');

        $cleanup = new KnowledgeFolderShareCleanup($files, $shares);
        $cleanup->forgetIfEmpty(7, null);
        $cleanup->forgetIfEmpty(7, '');
    }
}
