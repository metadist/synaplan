<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam\Erasure;

use App\Repository\ApprovalRepository;
use App\Service\Iam\Erasure\ApprovalEraser;
use App\Service\Iam\Erasure\UserErasure;
use PHPUnit\Framework\TestCase;

/**
 * Only the approvals the user owns are deleted, inside the transaction.
 */
final class ApprovalEraserTest extends TestCase
{
    public function testDeletesTheApprovalsTheUserOwns(): void
    {
        $approvals = $this->createMock(ApprovalRepository::class);
        $approvals->expects(self::once())->method('deleteByOwner')->with(42);

        $erasure = new UserErasure(42, 'ada@example.test');
        (new ApprovalEraser($approvals))->erase($erasure);

        self::assertSame([], $erasure->pendingCleanups());
    }
}
