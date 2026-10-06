<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\MessageDigest;
use App\Repository\MessageDigestIdCollisionException;
use App\Repository\MessageDigestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The upsert must only ever rewrite the row for the same (user, message).
 * A primary-key collision with another user's row is retried under a new id.
 */
class MessageDigestRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MessageDigestRepository $repository;
    private int $userA;
    private int $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $kernel = self::bootKernel();
        $this->em = $kernel->getContainer()->get('doctrine')->getManager();
        $repository = $this->em->getRepository(MessageDigest::class);
        self::assertInstanceOf(MessageDigestRepository::class, $repository);
        $this->repository = $repository;

        $this->userA = random_int(800_000_000, 890_000_000);
        $this->userB = $this->userA + 1;
    }

    protected function tearDown(): void
    {
        $stmt = $this->em->getConnection()->prepare(
            'DELETE FROM BMESSAGEDIGESTS WHERE BUSERID = :userA OR BUSERID = :userB',
        );
        $stmt->bindValue('userA', $this->userA);
        $stmt->bindValue('userB', $this->userB);
        $stmt->executeStatement();

        parent::tearDown();
    }

    public function testExistingUserMessageRowIsUpdatedAndItsIdReturned(): void
    {
        $messageId = random_int(800_000_000, 890_000_000);
        $existingId = random_int(8_000_000_000_000, 8_100_000_000_000);
        $this->insertRow($existingId, $this->userA, $messageId, 'old title');

        $returned = $this->repository->upsert(
            $this->digest($this->userA, $messageId, 'new title'),
            static function (): int {
                throw new \RuntimeException('id generator must not run when the row already exists');
            },
        );

        self::assertSame($existingId, $returned);
        self::assertSame('new title', $this->titleOf($existingId));
        self::assertSame(1, $this->countFor($this->userA, $messageId));
    }

    public function testPrimaryKeyCollisionDoesNotModifyTheOtherRowAndRetries(): void
    {
        $victimMessage = random_int(800_000_000, 890_000_000);
        $attackerMessage = $victimMessage + 1;
        $collidingId = random_int(8_200_000_000_000, 8_300_000_000_000);
        $freshId = $collidingId + 1;
        $this->insertRow($collidingId, $this->userA, $victimMessage, 'victim title');

        $calls = 0;
        $returned = $this->repository->upsert(
            $this->digest($this->userB, $attackerMessage, 'attacker title'),
            function () use (&$calls, $collidingId, $freshId): int {
                ++$calls;

                return 1 === $calls ? $collidingId : $freshId;
            },
        );

        self::assertSame(2, $calls);
        self::assertSame($freshId, $returned);
        self::assertSame('victim title', $this->titleOf($collidingId));
        self::assertSame('attacker title', $this->titleOf($freshId));
        self::assertSame($this->userA, $this->userOf($collidingId));
        self::assertSame($this->userB, $this->userOf($freshId));
        self::assertSame(0, $this->countFor($this->userB, $victimMessage));
    }

    public function testPrimaryKeyCollisionGivesUpAfterFiveAttempts(): void
    {
        $victimMessage = random_int(800_000_000, 890_000_000);
        $attackerMessage = $victimMessage + 3;
        $collidingId = random_int(8_400_000_000_000, 8_500_000_000_000);
        $this->insertRow($collidingId, $this->userA, $victimMessage, 'victim title');

        $calls = 0;
        try {
            $this->repository->upsert(
                $this->digest($this->userB, $attackerMessage, 'should not land'),
                function () use (&$calls, $collidingId): int {
                    ++$calls;

                    return $collidingId;
                },
            );
            self::fail('Expected a primary-key collision to exhaust the retries.');
        } catch (MessageDigestIdCollisionException $e) {
            self::assertSame($this->userB, $e->userId);
            self::assertSame($attackerMessage, $e->messageId);
            self::assertSame(5, $e->attempts);
        }

        self::assertSame(5, $calls);
        self::assertSame('victim title', $this->titleOf($collidingId));
        self::assertSame(0, $this->countFor($this->userB, $attackerMessage));
    }

    private function digest(int $userId, int $messageId, string $title): MessageDigest
    {
        $digest = new MessageDigest();
        $digest->setUserId($userId)
            ->setChatId(4)
            ->setMessageId($messageId)
            ->setTitle($title)
            ->setChannel('web')
            ->setSourceDate(1_700_000_000)
            ->setActive(true)
            ->setCreated(1_700_000_000);

        return $digest;
    }

    private function insertRow(int $id, int $userId, int $messageId, string $title): void
    {
        $stmt = $this->em->getConnection()->prepare(
            <<<'SQL'
                INSERT INTO BMESSAGEDIGESTS
                    (BID, BUSERID, BCHATID, BMESSAGEID, BTITLE, BCHANNEL, BSOURCEDATE, BACTIVE, BCREATED)
                VALUES
                    (:id, :userId, 4, :messageId, :title, 'web', 1700000000, 1, 1700000000)
                SQL,
        );
        $stmt->bindValue('id', $id);
        $stmt->bindValue('userId', $userId);
        $stmt->bindValue('messageId', $messageId);
        $stmt->bindValue('title', $title);
        $stmt->executeStatement();
    }

    private function titleOf(int $id): ?string
    {
        $value = $this->scalar('SELECT BTITLE FROM BMESSAGEDIGESTS WHERE BID = :id', $id);

        return null === $value ? null : (string) $value;
    }

    private function userOf(int $id): int
    {
        return (int) $this->scalar('SELECT BUSERID FROM BMESSAGEDIGESTS WHERE BID = :id', $id);
    }

    private function countFor(int $userId, int $messageId): int
    {
        $stmt = $this->em->getConnection()->prepare(
            'SELECT COUNT(*) FROM BMESSAGEDIGESTS WHERE BUSERID = :userId AND BMESSAGEID = :messageId',
        );
        $stmt->bindValue('userId', $userId);
        $stmt->bindValue('messageId', $messageId);

        return (int) $stmt->executeQuery()->fetchOne();
    }

    private function scalar(string $sql, int $id): mixed
    {
        $stmt = $this->em->getConnection()->prepare($sql);
        $stmt->bindValue('id', $id);
        $value = $stmt->executeQuery()->fetchOne();

        return false === $value ? null : $value;
    }
}
