<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam\Erasure;

use App\Service\Iam\Erasure\ResearchEraser;
use App\Service\Iam\Erasure\UserErasure;
use App\Service\Research\Run\ResearchStateStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ResearchEraserTest extends TestCase
{
    public function testErasureRemovesThePersonsResearchAfterTheAccountIsGone(): void
    {
        $store = $this->createMock(ResearchStateStore::class);
        $store->expects(self::once())->method('deleteUser')->with(4);
        $erasure = new UserErasure(4, 'person@example.com');

        (new ResearchEraser($store, new NullLogger()))->erase($erasure);

        self::assertCount(1, $erasure->pendingCleanups());
        foreach ($erasure->pendingCleanups() as $cleanup) {
            $cleanup();
        }
    }
}
