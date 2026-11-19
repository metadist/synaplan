<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam\Erasure;

use App\Repository\RunRepository;
use App\Service\Iam\Erasure\RunEraser;
use App\Service\Iam\Erasure\UserErasure;
use App\Service\Run\RunIds;
use App\Service\Run\RunKeyspace;
use App\Tests\Unit\Service\Run\MemoryRunKeys;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

final class RunEraserTest extends TestCase
{
    public function testRowsGoNowTheStarterIdLeavesOtherRunsAndRedisFollowsTheCommit(): void
    {
        $clock = new MockClock('2026-11-19 12:00:00');
        $keys = new MemoryRunKeys($clock);
        $keyspace = RunKeyspace::runs();
        $runId = RunIds::newId($clock);
        $keys->set($keyspace->cancel($runId), '1', 60);

        $runs = $this->createMock(RunRepository::class);
        $runs->expects(self::once())->method('uuidsForUser')->with(7)->willReturn([$runId, 'not-a-ulid']);
        $runs->expects(self::once())->method('deleteForUser')->with(7);
        $runs->expects(self::once())->method('clearStartedBy')->with(7);

        $erasure = new UserErasure(7, 'gone@example.test');
        (new RunEraser($runs, $keys, new NullLogger()))->erase($erasure);

        self::assertNotNull($keys->get($keyspace->cancel($runId)));
        foreach ($erasure->pendingCleanups() as $cleanup) {
            $cleanup();
        }
        self::assertNull($keys->get($keyspace->cancel($runId)));
    }
}
