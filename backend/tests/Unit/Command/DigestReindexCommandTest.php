<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\DigestReindexCommand;
use App\Entity\MessageDigest;
use App\Entity\User;
use App\Repository\MessageDigestRepository;
use App\Repository\UserRepository;
use App\Service\Digest\MessageDigestService;
use App\Service\VectorSearch\QdrantClientInterface;
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
    private QdrantClientInterface&MockObject $qdrantClient;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->digestRepository = $this->createMock(MessageDigestRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->digestService = $this->createMock(MessageDigestService::class);
        $this->qdrantClient = $this->createMock(QdrantClientInterface::class);

        $command = new DigestReindexCommand(
            $this->digestRepository,
            $this->userRepository,
            $this->digestService,
            $this->qdrantClient,
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
        $this->digestRepository->method('findDistinctUserIds')->willReturn([7, 9]);
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

    public function testDeletesThePointOfAnInactiveRow(): void
    {
        $this->userRepository->method('find')->willReturn($this->makeUser(self::USER_ID));
        $inactive = $this->digest(5);
        $inactive->setActive(false);
        $this->digestRepository->method('findInactiveForUserAfterId')
            ->willReturnOnConsecutiveCalls([$inactive], []);

        $this->qdrantClient->expects(self::once())
            ->method('deleteDigest')
            ->with(MessageDigestService::qdrantPointId(self::USER_ID, 5));

        $exitCode = $this->tester->execute(['--user' => (string) self::USER_ID]);

        self::assertSame(0, $exitCode);
    }

    public function testDeletesAnOrphanPointWhoseRowIsGone(): void
    {
        $this->userRepository->method('find')->willReturn($this->makeUser(self::USER_ID));
        $this->qdrantClient->method('scrollDigests')->willReturn([
            [
                'id' => 'dig_7_404',
                'payload' => [
                    '_point_id' => 'dig_7_404',
                    'user_id' => self::USER_ID,
                    'message_id' => 88,
                ],
            ],
        ]);
        $this->digestRepository->method('findExistingIds')->willReturn([]);

        $this->qdrantClient->expects(self::once())
            ->method('deleteDigest')
            ->with('dig_7_404');

        $exitCode = $this->tester->execute(['--user' => (string) self::USER_ID]);

        self::assertSame(0, $exitCode);
    }

    public function testDryRunDeletesNothing(): void
    {
        $this->userRepository->method('find')->willReturn($this->makeUser(self::USER_ID));
        $inactive = $this->digest(5);
        $inactive->setActive(false);
        $this->digestRepository->method('findInactiveForUserAfterId')
            ->willReturnOnConsecutiveCalls([$inactive], []);
        $this->qdrantClient->method('scrollDigests')->willReturn([
            [
                'id' => 'dig_7_404',
                'payload' => [
                    '_point_id' => 'dig_7_404',
                    'message_id' => 88,
                ],
            ],
        ]);
        $this->digestRepository->method('findExistingIds')->willReturn([]);

        $this->qdrantClient->expects(self::never())->method('deleteDigest');
        $this->digestService->expects(self::never())->method('mirrorToQdrant');

        $exitCode = $this->tester->execute([
            '--user' => (string) self::USER_ID,
            '--dry-run' => true,
        ]);

        self::assertSame(0, $exitCode);
        $display = preg_replace('/\s+/', ' ', $this->tester->getDisplay()) ?? '';
        self::assertStringContainsString('dig_7_5', $display);
        self::assertStringContainsString('dig_7_404', $display);
        self::assertStringContainsString('Nothing was deleted', $display);
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
