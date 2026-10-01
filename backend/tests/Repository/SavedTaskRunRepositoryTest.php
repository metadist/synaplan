<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Approval;
use App\Entity\SavedTaskRun;
use App\Repository\ApprovalRepository;
use App\Repository\SavedTaskRunRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SavedTaskRunRepositoryTest extends KernelTestCase
{
    public function testFailAbandonedOnlyTouchesRunsOlderThanTheCutoff(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $repo = $container->get(SavedTaskRunRepository::class);
        $connection = $container->get(Connection::class);
        $taskId = random_int(900_000_000, 999_999_999);

        $old = $this->runningRun($repo, $taskId);
        $fresh = $this->runningRun($repo, $taskId);
        $connection->executeStatement(
            'UPDATE BSAVEDTASK_RUNS SET BSTARTED = :started WHERE BID = :id',
            ['started' => gmdate('Y-m-d H:i:s', time() - 3600), 'id' => $old],
        );

        self::assertTrue($repo->hasActiveRunForTask($taskId));

        $cutoff = new \DateTimeImmutable('-30 minutes', new \DateTimeZone('UTC'));
        self::assertSame(1, $repo->failAbandoned($cutoff, 'interrupted'));

        $statuses = $connection->fetchAllKeyValue(
            'SELECT BID, BSTATUS FROM BSAVEDTASK_RUNS WHERE BSAVEDTASKID = :task',
            ['task' => $taskId],
        );
        self::assertSame(SavedTaskRun::STATUS_FAILED, $statuses[$old]);
        self::assertSame(SavedTaskRun::STATUS_RUNNING, $statuses[$fresh]);
        self::assertSame('interrupted', $connection->fetchOne('SELECT BERROR FROM BSAVEDTASK_RUNS WHERE BID = :id', ['id' => $old]));
        self::assertTrue($repo->hasActiveRunForTask($taskId), 'the fresh run is still active');

        $connection->executeStatement('DELETE FROM BSAVEDTASK_RUNS WHERE BSAVEDTASKID = :task', ['task' => $taskId]);
        self::assertFalse($repo->hasActiveRunForTask($taskId));
    }

    public function testExpireIfPendingWinsOnlyOnce(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $approvals = $container->get(ApprovalRepository::class);
        $connection = $container->get(Connection::class);

        $approval = new Approval(random_int(900_000_000, 999_999_999), 'task_run:1:n1', 'mcp:1:create', 'write', time() - 10);
        $approvals->save($approval);
        $id = (int) $approval->getId();

        self::assertTrue($approvals->expireIfPending($id, time()));
        self::assertFalse($approvals->expireIfPending($id, time()), 'a second sweep must not expire it again');
        self::assertSame(Approval::STATUS_EXPIRED, $connection->fetchOne('SELECT BSTATUS FROM BAPPROVALS WHERE BID = :id', ['id' => $id]));

        $connection->executeStatement('DELETE FROM BAPPROVALS WHERE BID = :id', ['id' => $id]);
    }

    private function runningRun(SavedTaskRunRepository $repo, int $taskId): int
    {
        $run = new SavedTaskRun($taskId, 'schedule');
        $run->markRunning();
        $repo->save($run);

        return (int) $run->getId();
    }
}
