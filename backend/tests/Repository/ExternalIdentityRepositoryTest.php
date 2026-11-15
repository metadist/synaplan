<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\ExternalIdentity;
use App\Repository\ExternalIdentityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ExternalIdentityRepositoryTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testUpsertInsertsOneRow(): void
    {
        [$source, $instanceId, $externalId] = $this->triple();
        $created = $this->repository()->upsert(41, $source, $externalId, $instanceId, null, ExternalIdentity::MODE_SSO);

        self::assertSame(41, $created->getUserId());
        self::assertSame(ExternalIdentity::MODE_SSO, $created->getMode());
        self::assertSame(ExternalIdentity::STATUS_ACTIVE, $created->getStatus());
        self::assertSame(1, $this->countTriples($source, $instanceId, $externalId));
    }

    public function testAVisibleRowIsReowned(): void
    {
        [$source, $instanceId, $externalId] = $this->triple();
        $created = $this->repository()->upsert(41, $source, $externalId, $instanceId, null, ExternalIdentity::MODE_SSO);
        $this->stamp($created, time() + 3600, 41, ExternalIdentity::MODE_SSO, ExternalIdentity::STATUS_ACTIVE, null);

        $updated = $this->repository()->upsert(42, $source, $externalId, $instanceId, 7, ExternalIdentity::MODE_LINK);

        self::assertSame(42, $updated->getUserId());
        self::assertSame(ExternalIdentity::MODE_LINK, $updated->getMode());
        self::assertSame(7, $updated->getApiKeyId());
        self::assertGreaterThanOrEqual(time() - 2, $updated->getLastSeen());
        self::assertSame(1, $this->countTriples($source, $instanceId, $externalId));
    }

    public function testALostInsertKeepsANewerRow(): void
    {
        [$source, $instanceId, $externalId] = $this->triple();
        $created = $this->repository()->upsert(41, $source, $externalId, $instanceId, 9, ExternalIdentity::MODE_SSO);
        $future = time() + 3600;
        $this->stamp($created, $future, 41, ExternalIdentity::MODE_SSO, ExternalIdentity::STATUS_REVOKED, 9);

        $kept = $this->repositoryThatMissesTheFirstLookup()->upsert(
            42,
            $source,
            $externalId,
            $instanceId,
            55,
            ExternalIdentity::MODE_LINK,
        );

        self::assertSame(41, $kept->getUserId());
        self::assertSame(ExternalIdentity::MODE_SSO, $kept->getMode());
        self::assertSame(9, $kept->getApiKeyId());
        self::assertSame(ExternalIdentity::STATUS_REVOKED, $kept->getStatus());
        self::assertSame($future, $kept->getLastSeen());
        self::assertSame(1, $this->countTriples($source, $instanceId, $externalId));
    }

    public function testALostInsertDoesNotTakeAnotherUsersOlderRow(): void
    {
        [$source, $instanceId, $externalId] = $this->triple();
        $created = $this->repository()->upsert(41, $source, $externalId, $instanceId, 9, ExternalIdentity::MODE_SSO);
        $seen = time() - 120;
        $this->stamp($created, $seen, 41, ExternalIdentity::MODE_SSO, ExternalIdentity::STATUS_REVOKED, 9);

        $kept = $this->repositoryThatMissesTheFirstLookup()->upsert(
            42,
            $source,
            $externalId,
            $instanceId,
            55,
            ExternalIdentity::MODE_LINK,
        );

        self::assertSame(41, $kept->getUserId());
        self::assertSame(ExternalIdentity::MODE_SSO, $kept->getMode());
        self::assertSame(9, $kept->getApiKeyId());
        self::assertSame(ExternalIdentity::STATUS_REVOKED, $kept->getStatus());
        self::assertSame($seen, $kept->getLastSeen());
        self::assertSame(1, $this->countTriples($source, $instanceId, $externalId));
    }

    public function testInsertIfAbsentDoesNotRewriteAnExistingRow(): void
    {
        [$source, $instanceId, $externalId] = $this->triple();
        $created = $this->repository()->insertIfAbsent(41, $source, $externalId, $instanceId, null, ExternalIdentity::MODE_SSO);
        self::assertInstanceOf(ExternalIdentity::class, $created);
        $seen = $created->getLastSeen();
        $this->stamp($created, $seen, 41, ExternalIdentity::MODE_SSO, ExternalIdentity::STATUS_REVOKED, null);

        self::assertNull($this->repository()->insertIfAbsent(42, $source, $externalId, $instanceId, 5, ExternalIdentity::MODE_LINK));

        $stored = $this->repository()->findOneByTriple($source, $instanceId, $externalId);
        self::assertInstanceOf(ExternalIdentity::class, $stored);
        self::assertSame(41, $stored->getUserId());
        self::assertSame(ExternalIdentity::MODE_SSO, $stored->getMode());
        self::assertNull($stored->getApiKeyId());
        self::assertSame(ExternalIdentity::STATUS_REVOKED, $stored->getStatus());
        self::assertSame($seen, $stored->getLastSeen());
        self::assertSame(1, $this->countTriples($source, $instanceId, $externalId));
    }

    public function testARepeatableReadSnapshotStillReadsTheRowAnotherConnectionCommitted(): void
    {
        [$source, $instanceId, $externalId] = $this->triple();
        $seen = time() - 30;
        self::assertNull($this->repository()->findOneByTriple($source, $instanceId, $externalId));

        $other = $this->otherConnection();
        try {
            $other->insert('BEXTERNALIDENTITIES', [
                'BUSERID' => 41,
                'BSOURCE' => $source,
                'BINSTANCEID' => $instanceId,
                'BEXTERNALID' => $externalId,
                'BAPIKEYID' => 9,
                'BMODE' => ExternalIdentity::MODE_SSO,
                'BSTATUS' => ExternalIdentity::STATUS_REVOKED,
                'BCREATED' => $seen,
                'BLASTSEEN' => $seen,
            ], [
                'BUSERID' => ParameterType::INTEGER,
                'BAPIKEYID' => ParameterType::INTEGER,
                'BCREATED' => ParameterType::INTEGER,
                'BLASTSEEN' => ParameterType::INTEGER,
            ]);

            $kept = $this->repository()->upsert(42, $source, $externalId, $instanceId, 55, ExternalIdentity::MODE_LINK);

            self::assertSame(41, $kept->getUserId());
            self::assertSame(ExternalIdentity::MODE_SSO, $kept->getMode());
            self::assertSame(9, $kept->getApiKeyId());
            self::assertSame(ExternalIdentity::STATUS_REVOKED, $kept->getStatus());
            self::assertSame($seen, $kept->getLastSeen());
        } finally {
            // The committed insert outlives this test's transaction. Deleting it
            // from here waits on the transaction's index lock, so the unique
            // key is left for the database to drop with the test schema.
            $other->close();
        }
    }

    private function repository(): ExternalIdentityRepository
    {
        $repository = static::getContainer()->get(ExternalIdentityRepository::class);
        self::assertInstanceOf(ExternalIdentityRepository::class, $repository);

        return $repository;
    }

    /**
     * The first lookup misses, so the write takes the insert path and hits
     * the unique key the way two concurrent first requests do.
     */
    private function repositoryThatMissesTheFirstLookup(): ExternalIdentityRepository
    {
        $real = $this->repository();
        $registry = static::getContainer()->get(ManagerRegistry::class);
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $seen = false;
        $repository = $this->getMockBuilder(ExternalIdentityRepository::class)
            ->setConstructorArgs([$registry])
            ->onlyMethods(['findOneByTriple'])
            ->getMock();
        $repository->expects(self::atLeastOnce())->method('findOneByTriple')->willReturnCallback(
            function (string $source, string $instanceId, string $externalId) use ($real, &$seen): ?ExternalIdentity {
                if (!$seen) {
                    $seen = true;

                    return null;
                }

                return $real->findOneByTriple($source, $instanceId, $externalId);
            },
        );

        return $repository;
    }

    private function otherConnection(): Connection
    {
        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $params = $connection->getParams();
        $other = DriverManager::getConnection([
            'driver' => is_string($params['driver'] ?? null) ? $params['driver'] : 'pdo_mysql',
            'host' => $params['host'] ?? 'db',
            'port' => $params['port'] ?? 3306,
            'user' => $params['user'] ?? '',
            'password' => $params['password'] ?? '',
            'dbname' => $params['dbname'] ?? '',
            'charset' => 'utf8mb4',
        ]);
        self::assertInstanceOf(Connection::class, $other);
        $other->executeStatement('SET innodb_lock_wait_timeout = 5');

        return $other;
    }

    /** @return array{string, string, string} */
    private function triple(): array
    {
        $suffix = bin2hex(random_bytes(8));

        return ['test-extid-'.$suffix, 'inst-'.$suffix, 'ext-'.$suffix];
    }

    private function stamp(
        ExternalIdentity $identity,
        int $lastSeen,
        int $userId,
        string $mode,
        string $status,
        ?int $apiKeyId,
    ): void {
        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement(
            'UPDATE BEXTERNALIDENTITIES
             SET BLASTSEEN = :lastSeen, BUSERID = :userId, BMODE = :mode, BSTATUS = :status, BAPIKEYID = :apiKeyId
             WHERE BID = :id',
            [
                'lastSeen' => $lastSeen,
                'userId' => $userId,
                'mode' => $mode,
                'status' => $status,
                'apiKeyId' => $apiKeyId,
                'id' => $identity->getId(),
            ],
            [
                'lastSeen' => ParameterType::INTEGER,
                'userId' => ParameterType::INTEGER,
                'apiKeyId' => null === $apiKeyId ? ParameterType::NULL : ParameterType::INTEGER,
                'id' => ParameterType::INTEGER,
            ],
        );
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();
    }

    private function countTriples(string $source, string $instanceId, string $externalId): int
    {
        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM BEXTERNALIDENTITIES WHERE BSOURCE = :source AND BINSTANCEID = :instanceId AND BEXTERNALID = :externalId',
            ['source' => $source, 'instanceId' => $instanceId, 'externalId' => $externalId],
        );
    }
}
