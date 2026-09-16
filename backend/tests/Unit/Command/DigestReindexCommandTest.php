<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\DigestReindexCommand;
use App\Entity\MessageDigest;
use App\Entity\User;
use App\Repository\MessageDigestRepository;
use App\Repository\UserRepository;
use App\Service\Digest\MessageDigestService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class DigestReindexCommandTest extends TestCase
{
    private const USER_ID = 7;

    private MessageDigestRepository&MockObject $digestRepository;
    private UserRepository&MockObject $userRepository;
    private MessageDigestService&MockObject $digestService;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->digestRepository = $this->createMock(MessageDigestRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->digestService = $this->createMock(MessageDigestService::class);

        $command = new DigestReindexCommand(
            $this->digestRepository,
            $this->userRepository,
            $this->digestService,
            new LockFactory(new InMemoryStore()),
        );

        $this->tester = new CommandTester($command);
    }

    public function testRefusesToRunWithoutAUserScope(): void
    {
        $this->digestService->expects(self::never())->method('mirrorToQdrant');

        $exitCode = $this->tester->execute([]);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('--user', $this->tester->getDisplay());
    }

    public function testReindexesEveryActiveDigestOfAUserInKeysetPages(): void
    {
        $user = $this->makeUser(self::USER_ID);
        $this->userRepository->method('find')->willReturn($user);

        $page1 = [$this->digest(1), $this->digest(2)];
        $page2 = [$this->digest(3)];

        $capturedAfterIds = [];
        $this->digestRepository->method('findActiveForUserAfterId')
            ->willReturnCallback(function (int $userId, int $afterId) use (&$capturedAfterIds, $page1, $page2): array {
                $capturedAfterIds[] = $afterId;

                return match (count($capturedAfterIds)) {
                    1 => $page1,
                    2 => $page2,
                    default => [],
                };
            });

        $mirrored = [];
        $this->digestService->method('mirrorToQdrant')
            ->willReturnCallback(static function (User $user, MessageDigest $digest) use (&$mirrored): bool {
                $mirrored[] = $digest->getId();

                return true;
            });

        $exitCode = $this->tester->execute(['--user' => (string) self::USER_ID, '--page-size' => '2']);

        self::assertSame(0, $exitCode);
        self::assertSame([0, 2, 3], $capturedAfterIds);
        self::assertSame([1, 2, 3], $mirrored);
        self::assertStringContainsString('3 points rebuilt, 0 failed', $this->tester->getDisplay());
    }

    public function testFailedPointsAreCountedAndFailTheCommand(): void
    {
        $this->userRepository->method('find')->willReturn($this->makeUser(self::USER_ID));
        $this->digestRepository->method('findActiveForUserAfterId')
            ->willReturnOnConsecutiveCalls([$this->digest(1), $this->digest(2)], []);

        $this->digestService->method('mirrorToQdrant')
            ->willReturnOnConsecutiveCalls(true, false);

        $exitCode = $this->tester->execute(['--user' => (string) self::USER_ID]);

        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('1 points rebuilt, 1 failed', $this->tester->getDisplay());
    }

    public function testAllUsersEnumeratesUsersWithActiveDigests(): void
    {
        $this->digestRepository->method('findDistinctActiveUserIds')->willReturn([7, 9]);
        $this->userRepository->method('find')
            ->willReturnCallback(fn (mixed $id): ?User => 7 === $id ? $this->makeUser(7) : null);

        $this->digestRepository->method('findActiveForUserAfterId')
            ->willReturnOnConsecutiveCalls([$this->digest(1)], []);
        $this->digestService->method('mirrorToQdrant')->willReturn(true);

        $exitCode = $this->tester->execute(['--all-users' => true]);

        self::assertSame(0, $exitCode);
        // User 9 no longer exists — warned and skipped, not fatal.
        self::assertStringContainsString('User 9 not found', $this->tester->getDisplay());
        self::assertStringContainsString('1 users, 1 points rebuilt', $this->tester->getDisplay());
    }

    private function makeUser(int $id): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function digest(int $id): MessageDigest
    {
        $digest = new MessageDigest();
        $digest->setId($id)
            ->setUserId(self::USER_ID)
            ->setChatId(42)
            ->setMessageId($id * 10)
            ->setTitle('digest '.$id)
            ->setChannel('web')
            ->setSourceDate(1_700_000_000)
            ->setActive(true)
            ->setCreated(1_700_000_000);

        return $digest;
    }
}
