<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam;

use App\Entity\User;
use App\Service\Iam\Erasure\UserDataEraserInterface;
use App\Service\Iam\Erasure\UserErasure;
use App\Service\Iam\UserDeletionService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Account deletion: every eraser inside one transaction in order, then the
 * user row, the commit, and only then the cleanups outside the database.
 * Any failure — including one before the first eraser — rolls back, is
 * logged and reaches the caller.
 */
final class UserDeletionServiceTest extends TestCase
{
    /** @var list<string> */
    private array $events = [];
    private bool $inTransaction = false;

    public function testErasersRunInOrderInsideTheTransactionAndCleanupsAfterTheCommit(): void
    {
        $user = $this->user(42, 'ada@example.test');
        $first = $this->eraser('first', function (UserErasure $erasure): void {
            $erasure->afterCommit(function (): void {
                $this->events[] = 'cleanup:first';
            });
        });
        $second = $this->eraser('second');

        $service = new UserDeletionService($this->entityManager($user), [$first, $second], new NullLogger());
        $service->deleteUser($user);

        self::assertSame(
            ['begin', 'erase:first:42:ada@example.test', 'erase:second:42:ada@example.test', 'remove', 'flush', 'commit', 'cleanup:first'],
            $this->events,
        );
    }

    public function testACleanupThatFailsAfterTheCommitIsLoggedAndTheDeletionStillCompletes(): void
    {
        $user = $this->user(42, 'ada@example.test');
        $eraser = $this->eraser('first', function (UserErasure $erasure): void {
            $erasure->afterCommit(static fn () => throw new \RuntimeException('sidecar unreachable'));
            $erasure->afterCommit(function (): void {
                $this->events[] = 'cleanup:second';
            });
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'Account deletion cleanup failed after the commit',
            self::callback(static fn (array $context): bool => 42 === $context['user_id'] && $context['exception'] instanceof \RuntimeException),
        );

        (new UserDeletionService($this->entityManager($user), [$eraser], $logger))->deleteUser($user);

        self::assertSame(
            ['begin', 'erase:first:42:ada@example.test', 'remove', 'flush', 'commit', 'cleanup:second'],
            $this->events,
        );
    }

    public function testAFailingEraserRollsBackLogsAndRethrows(): void
    {
        $user = $this->user(42, 'ada@example.test');
        $failing = $this->eraser('failing', static fn () => throw new \RuntimeException('lock wait timeout'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Failed to delete user', self::anything());

        $service = new UserDeletionService($this->entityManager($user), [$failing], $logger);

        try {
            $service->deleteUser($user);
            self::fail('The failure must reach the caller.');
        } catch (\RuntimeException $e) {
            self::assertSame('lock wait timeout', $e->getMessage());
        }
        self::assertSame(['begin', 'erase:failing:42:ada@example.test', 'rollback'], $this->events);
    }

    public function testAUserWithoutAnIdRollsBackAndIsLoggedLikeAnyOtherFailure(): void
    {
        $user = $this->user(null, 'ghost@example.test');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Failed to delete user', self::anything());

        $service = new UserDeletionService($this->entityManager($user), [$this->eraser('never')], $logger);

        try {
            $service->deleteUser($user);
            self::fail('A user without an id cannot be erased.');
        } catch (\TypeError) {
        }
        self::assertSame(['begin', 'rollback'], $this->events);
    }

    public function testAFlushThatEndedTheTransactionKeepsTheRealCause(): void
    {
        $user = $this->user(42, 'ada@example.test');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Failed to delete user', self::callback(
            static fn (array $context): bool => 'Deadlock found' === $context['error'],
        ));

        $service = new UserDeletionService($this->entityManager($user, new \RuntimeException('Deadlock found')), [], $logger);

        try {
            $service->deleteUser($user);
            self::fail('The failure must reach the caller.');
        } catch (\RuntimeException $e) {
            self::assertSame('Deadlock found', $e->getMessage());
        }
        self::assertSame(['begin', 'remove', 'flush'], $this->events);
    }

    /**
     * @param (\Closure(UserErasure): mixed)|null $behaviour
     */
    private function eraser(string $name, ?\Closure $behaviour = null): UserDataEraserInterface
    {
        $eraser = $this->createStub(UserDataEraserInterface::class);
        $eraser->method('erase')->willReturnCallback(function (UserErasure $erasure) use ($name, $behaviour): void {
            $this->events[] = 'erase:'.$name.':'.$erasure->userId.':'.$erasure->email;
            if (null !== $behaviour) {
                $behaviour($erasure);
            }
        });

        return $eraser;
    }

    private function user(?int $id, string $mail): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getMail')->willReturn($mail);

        return $user;
    }

    private function entityManager(User $user, ?\Throwable $flushFailure = null): EntityManagerInterface
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('beginTransaction')->willReturnCallback(function (): void {
            $this->events[] = 'begin';
            $this->inTransaction = true;
        });
        $connection->method('commit')->willReturnCallback(function (): void {
            $this->events[] = 'commit';
            $this->inTransaction = false;
        });
        $connection->method('rollBack')->willReturnCallback(function (): void {
            $this->events[] = 'rollback';
            $this->inTransaction = false;
        });
        $connection->method('isTransactionActive')->willReturnCallback(fn (): bool => $this->inTransaction);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('remove')->willReturnCallback(function (object $entity) use ($user): void {
            self::assertSame($user, $entity);
            $this->events[] = 'remove';
        });
        $em->method('flush')->willReturnCallback(function () use ($flushFailure): void {
            $this->events[] = 'flush';
            if (null !== $flushFailure) {
                // The driver ended the transaction along with the failed statement.
                $this->inTransaction = false;
                throw $flushFailure;
            }
        });

        return $em;
    }
}
