<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam\Erasure;

use App\Entity\ComputeWorkspace;
use App\Message\DeleteComputeWorkspaceCommand;
use App\Repository\ComputeRunRepository;
use App\Repository\ComputeWorkspaceRepository;
use App\Service\Iam\Erasure\ComputeEraser;
use App\Service\Iam\Erasure\UserErasure;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Runs and workspace rows go inside the transaction; the sidecar folder of a
 * live workspace is queued for deletion only after the commit, and a queue
 * that refuses the message never fails the deletion.
 */
final class ComputeEraserTest extends TestCase
{
    private const USER_ID = 42;

    /** @var list<object> */
    private array $dispatched = [];

    public function testRemovesRunsAndWorkspacesAndQueuesTheLiveFolderAfterTheCommit(): void
    {
        $live = new ComputeWorkspace(self::USER_ID, '01JLIVE', 256);
        $runs = $this->createMock(ComputeRunRepository::class);
        $runs->expects(self::once())->method('deleteByUser')->with(self::USER_ID);
        $removed = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('remove')->willReturnCallback(static function (object $row) use (&$removed): void {
            $removed[] = $row;
        });

        $erasure = new UserErasure(self::USER_ID, 'ada@example.test');
        $this->eraser([$live], $runs, $em)->erase($erasure);

        self::assertSame([$live], $removed);
        self::assertSame([], $this->dispatched, 'Nothing may be queued before the commit.');
        $this->runCleanups($erasure);
        self::assertEquals([new DeleteComputeWorkspaceCommand('01JLIVE')], $this->dispatched);
    }

    public function testAFolderTheExpirerAlreadyDeletedIsNotQueuedAgain(): void
    {
        $deleted = new ComputeWorkspace(self::USER_ID, '01JGONE', 256);
        $deleted->markDeleted();

        $erasure = new UserErasure(self::USER_ID, 'ada@example.test');
        $this->eraser([$deleted])->erase($erasure);

        self::assertSame([], $erasure->pendingCleanups());
    }

    public function testAQueueThatRefusesTheMessageIsLoggedWithTheWorkspaceId(): void
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('Redis went away'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            self::anything(),
            self::callback(static fn (array $context): bool => '01JLIVE' === $context['workspace_id']),
        );

        $erasure = new UserErasure(self::USER_ID, 'ada@example.test');
        $this->eraser([new ComputeWorkspace(self::USER_ID, '01JLIVE', 256)], bus: $bus, logger: $logger)->erase($erasure);
        $this->runCleanups($erasure);
    }

    /**
     * @param list<ComputeWorkspace> $workspaces
     */
    private function eraser(
        array $workspaces,
        ?ComputeRunRepository $runs = null,
        ?EntityManagerInterface $em = null,
        ?MessageBusInterface $bus = null,
        ?LoggerInterface $logger = null,
    ): ComputeEraser {
        $repository = $this->createStub(ComputeWorkspaceRepository::class);
        $repository->method('findBy')->willReturn($workspaces);
        if (null === $bus) {
            $bus = $this->createStub(MessageBusInterface::class);
            $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
                $this->dispatched[] = $message;

                return new Envelope($message);
            });
        }

        return new ComputeEraser(
            $em ?? $this->createStub(EntityManagerInterface::class),
            $runs ?? $this->createStub(ComputeRunRepository::class),
            $repository,
            $bus,
            $logger ?? new NullLogger(),
        );
    }

    private function runCleanups(UserErasure $erasure): void
    {
        foreach ($erasure->pendingCleanups() as $cleanup) {
            $cleanup();
        }
    }
}
