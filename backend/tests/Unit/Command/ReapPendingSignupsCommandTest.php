<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\ReapPendingSignupsCommand;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Iam\Erasure\UserDataEraserInterface;
use App\Service\Iam\Erasure\UserErasure;
use App\Service\Iam\UserDeletionService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * A failed erasure must not leak into the next one: the reaper discards the
 * unit of work and reads the following accounts fresh before it continues.
 */
final class ReapPendingSignupsCommandTest extends TestCase
{
    /** @var list<string> */
    private array $events = [];
    private bool $inTransaction = false;

    public function testAFailedErasureIsDiscardedBeforeTheNextAccount(): void
    {
        $first = self::pendingUser(1);
        $second = self::pendingUser(2);
        $freshSecond = self::pendingUser(2);

        $users = $this->createMock(UserRepository::class);
        $users->expects(self::exactly(2))->method('findPendingPaymentAfterId')
            ->willReturnCallback(function (int $afterId) use ($first, $second, $freshSecond): array {
                $this->events[] = 'page:after-'.$afterId;

                return 0 === $afterId ? [$first, $second] : [$freshSecond];
            });

        $em = $this->entityManager();
        $failsForTheFirst = $this->createStub(UserDataEraserInterface::class);
        $failsForTheFirst->method('erase')->willReturnCallback(function (UserErasure $erasure): void {
            $this->events[] = 'erase:'.$erasure->userId;
            if (1 === $erasure->userId) {
                throw new \RuntimeException('lock wait timeout');
            }
        });

        $command = new ReapPendingSignupsCommand(
            $users,
            new UserDeletionService($em, [$failsForTheFirst], new NullLogger()),
            $em,
            new LockFactory(new InMemoryStore()),
            new NullLogger(),
        );
        $application = new Application();
        $application->addCommand($command);
        $tester = new CommandTester($application->find('app:signup:reap-pending'));

        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Deleted 1 pending sign-up(s)', $tester->getDisplay());
        self::assertStringContainsString('1 failed', $tester->getDisplay());
        self::assertSame([
            'page:after-0',
            'begin', 'erase:1', 'rollback',
            'clear',
            'page:after-1',
            'begin', 'erase:2', 'remove:2', 'flush', 'commit',
        ], $this->events);
    }

    private function entityManager(): EntityManagerInterface
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
        $em->method('isOpen')->willReturn(true);
        $em->method('remove')->willReturnCallback(function (object $entity): void {
            $this->events[] = 'remove:'.($entity instanceof User ? $entity->getId() : '?');
        });
        $em->method('flush')->willReturnCallback(function (): void {
            $this->events[] = 'flush';
        });
        $em->method('clear')->willReturnCallback(function (): void {
            $this->events[] = 'clear';
        });

        return $em;
    }

    private static function pendingUser(int $id): User
    {
        $user = (new User())
            ->setMail('pending'.$id.'@example.test')
            ->setAccountStatus(User::ACCOUNT_STATUS_PENDING_PAYMENT);
        $user->setCreated(date('Y-m-d H:i:s', time() - 8 * 86400));
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
