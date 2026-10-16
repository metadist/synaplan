<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam\Erasure;

use App\Repository\UrlWatchRepository;
use App\Service\Iam\Erasure\UrlWatchEraser;
use App\Service\Iam\Erasure\UserErasure;
use PHPUnit\Framework\TestCase;

/**
 * The user's watched pages are deleted inside the transaction.
 */
final class UrlWatchEraserTest extends TestCase
{
    public function testDeletesTheWatchesTheUserOwns(): void
    {
        $watches = $this->createMock(UrlWatchRepository::class);
        $watches->expects(self::once())->method('deleteByOwner')->with(42);

        $erasure = new UserErasure(42, 'ada@example.test');
        (new UrlWatchEraser($watches))->erase($erasure);

        self::assertSame([], $erasure->pendingCleanups());
    }
}
