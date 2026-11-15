<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ExternalIdentity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExternalIdentity>
 */
class ExternalIdentityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExternalIdentity::class);
    }

    /**
     * @param callable(ExternalIdentity): void $change
     */
    public function modify(int $id, callable $change): ?ExternalIdentity
    {
        /** @var ExternalIdentity|null $identity */
        $identity = $this->getEntityManager()->wrapInTransaction(function () use ($id, $change): ?ExternalIdentity {
            $identity = $this->find($id, LockMode::PESSIMISTIC_WRITE);
            if (!$identity instanceof ExternalIdentity) {
                return null;
            }
            $this->getEntityManager()->refresh($identity);
            $change($identity);
            $this->getEntityManager()->flush();

            return $identity;
        });

        return $identity;
    }

    public function save(ExternalIdentity $identity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($identity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findOneByTriple(string $source, string $instanceId, string $externalId): ?ExternalIdentity
    {
        return $this->findOneBy([
            'source' => $source,
            'instanceId' => $instanceId,
            'externalId' => $externalId,
        ]);
    }

    /**
     * @return list<ExternalIdentity>
     */
    public function findByUserId(int $userId): array
    {
        /** @var list<ExternalIdentity> $rows */
        $rows = $this->findBy(['userId' => $userId], ['created' => 'ASC']);

        return $rows;
    }

    /**
     * @param list<int> $userIds
     *
     * @return list<ExternalIdentity>
     */
    public function findByUserIds(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        /** @var list<ExternalIdentity> $rows */
        $rows = $this->createQueryBuilder('e')
            ->where('e.userId IN (:ids)')
            ->setParameter('ids', $userIds)
            ->orderBy('e.created', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Insert or update the (source, instanceId, externalId) row and bump lastSeen.
     *
     * A row this call can already see is re-owned. The row is keyed by the
     * external identity, not by the Synaplan user: the caller has just proven
     * that this external identity belongs to `$userId` now. Leaving the old
     * owner behind would let them disconnect the link and revoke the new
     * owner's key, while the new owner would not see the link at all.
     *
     * A row that appears between the lookup and the insert is a lost race.
     * The winner is returned unchanged: owner, mode, key, status and
     * `lastSeen` stay as the first writer left them. A later call that can
     * already see the row is the intentional update, and that path still
     * re-owns. Freshness is not a licence for the loser to take the row.
     */
    public function upsert(
        int $userId,
        string $source,
        string $externalId,
        string $instanceId = '',
        ?int $apiKeyId = null,
        ?string $mode = null,
    ): ExternalIdentity {
        $identity = $this->findOneByTriple($source, $instanceId, $externalId);
        if ($identity instanceof ExternalIdentity) {
            $this->reown($identity, $userId, $apiKeyId, $mode);

            return $identity;
        }

        $now = time();
        try {
            $this->insertRow($userId, $source, $externalId, $instanceId, $apiKeyId, $mode, $now);
        } catch (UniqueConstraintViolationException) {
            $winner = $this->current($source, $instanceId, $externalId);
            if ($winner instanceof ExternalIdentity) {
                return $winner;
            }

            throw new \RuntimeException(sprintf('External identity "%s" / "%s" / "%s" was written but could not be read back.', $source, $instanceId, $externalId));
        }

        $created = $this->requireByTriple($source, $instanceId, $externalId);
        $this->getEntityManager()->flush();

        return $created;
    }

    /**
     * Insert the triple when it is absent.
     *
     * Returns null when the row is already there, including when another
     * request inserts it between the lookup and this insert. The stored row
     * is not rewritten: a revoked link stays revoked, and the caller that
     * lost the race must not repeat the winner's side effects.
     */
    public function insertIfAbsent(
        int $userId,
        string $source,
        string $externalId,
        string $instanceId = '',
        ?int $apiKeyId = null,
        ?string $mode = null,
    ): ?ExternalIdentity {
        if ($this->findOneByTriple($source, $instanceId, $externalId) instanceof ExternalIdentity) {
            return null;
        }

        try {
            $this->insertRow($userId, $source, $externalId, $instanceId, $apiKeyId, $mode, time());
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return $this->requireByTriple($source, $instanceId, $externalId);
    }

    private function reown(ExternalIdentity $identity, int $userId, ?int $apiKeyId, ?string $mode): void
    {
        $identity->setUserId($userId);
        $identity->touchLastSeen();
        if (null !== $apiKeyId) {
            $identity->setApiKeyId($apiKeyId);
        }
        if (null !== $mode) {
            $identity->setMode($mode);
        }

        $this->getEntityManager()->flush();
    }

    /**
     * The latest committed row for this triple.
     *
     * Read on a new connection. This transaction may already have a REPEATABLE
     * READ snapshot from the lookup that missed the row, and on MariaDB a
     * locking read of a row committed after that snapshot raises
     * "Record has changed since last read" instead of returning it. The new
     * connection has no snapshot, so the request that lost the insert sees
     * the winner. The entity is registered here so a later flush updates it.
     */
    public function current(string $source, string $instanceId, string $externalId): ?ExternalIdentity
    {
        $row = $this->fetchCurrent($source, $instanceId, $externalId);
        if (is_array($row)) {
            return $this->manageCurrent($row);
        }

        // The winner may be an uncommitted insert of this transaction. A new
        // connection cannot see that, and this transaction can.
        return $this->findOneByTriple($source, $instanceId, $externalId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchCurrent(string $source, string $instanceId, string $externalId): ?array
    {
        $fresh = $this->freshConnection();
        try {
            $row = $fresh->fetchAssociative(
                'SELECT BID, BUSERID, BSOURCE, BINSTANCEID, BEXTERNALID, BAPIKEYID, BMODE, BSTATUS, BREVOKEDAT, BCREATED, BLASTSEEN, BREADSCOPE
                 FROM BEXTERNALIDENTITIES
                 WHERE BSOURCE = :source AND BINSTANCEID = :instanceId AND BEXTERNALID = :externalId',
                ['source' => $source, 'instanceId' => $instanceId, 'externalId' => $externalId],
            );
        } finally {
            $fresh->close();
        }

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function manageCurrent(array $row): ExternalIdentity
    {
        $id = (int) $row['BID'];
        $known = $this->getEntityManager()->getUnitOfWork()->tryGetById(['id' => $id], ExternalIdentity::class);
        $identity = $known instanceof ExternalIdentity ? $known : new ExternalIdentity();
        $this->applyRow($identity, $row);
        $original = $this->originalData($identity);
        $unitOfWork = $this->getEntityManager()->getUnitOfWork();
        if ($known instanceof ExternalIdentity) {
            $unitOfWork->setOriginalEntityData($identity, $original);

            return $identity;
        }

        $unitOfWork->registerManaged($identity, ['id' => $id], $original);

        return $identity;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function applyRow(ExternalIdentity $identity, array $row): void
    {
        $this->write($identity, 'id', (int) $row['BID']);
        $identity->setUserId((int) $row['BUSERID']);
        $identity->setSource((string) $row['BSOURCE']);
        $identity->setInstanceId((string) $row['BINSTANCEID']);
        $identity->setExternalId((string) $row['BEXTERNALID']);
        $identity->setApiKeyId(null === $row['BAPIKEYID'] ? null : (int) $row['BAPIKEYID']);
        $identity->setMode(null === $row['BMODE'] ? null : (string) $row['BMODE']);
        $identity->setStatus((string) $row['BSTATUS']);
        $identity->setRevokedAt(null === $row['BREVOKEDAT'] ? null : (int) $row['BREVOKEDAT']);
        $this->write($identity, 'created', (int) $row['BCREATED']);
        $identity->setLastSeen((int) $row['BLASTSEEN']);
        $this->write($identity, 'readScope', null === $row['BREADSCOPE'] ? null : (string) $row['BREADSCOPE']);
    }

    private function write(ExternalIdentity $identity, string $property, mixed $value): void
    {
        $field = new \ReflectionProperty(ExternalIdentity::class, $property);
        $field->setValue($identity, $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function originalData(ExternalIdentity $identity): array
    {
        $readScope = new \ReflectionProperty(ExternalIdentity::class, 'readScope');

        return [
            'id' => $identity->getId(),
            'userId' => $identity->getUserId(),
            'source' => $identity->getSource(),
            'instanceId' => $identity->getInstanceId(),
            'externalId' => $identity->getExternalId(),
            'apiKeyId' => $identity->getApiKeyId(),
            'mode' => $identity->getMode(),
            'status' => $identity->getStatus(),
            'revokedAt' => $identity->getRevokedAt(),
            'created' => $identity->getCreated(),
            'lastSeen' => $identity->getLastSeen(),
            'readScope' => $readScope->getValue($identity),
        ];
    }

    private function freshConnection(): Connection
    {
        $params = $this->getEntityManager()->getConnection()->getParams();

        return DriverManager::getConnection([
            'driver' => is_string($params['driver'] ?? null) ? $params['driver'] : 'pdo_mysql',
            'host' => $params['host'] ?? 'db',
            'port' => $params['port'] ?? 3306,
            'user' => $params['user'] ?? '',
            'password' => $params['password'] ?? '',
            'dbname' => $params['dbname'] ?? '',
            'charset' => 'utf8mb4',
        ]);
    }

    private function insertRow(
        int $userId,
        string $source,
        string $externalId,
        string $instanceId,
        ?int $apiKeyId,
        ?string $mode,
        int $now,
    ): void {
        $connection = $this->getEntityManager()->getConnection();
        // A failed insert must not abort the transaction a caller already
        // opened (the test suite wraps every test in one). A savepoint keeps
        // that transaction usable after the unique-key conflict.
        $nested = $connection->isTransactionActive();
        if ($nested) {
            $connection->beginTransaction();
        }

        try {
            $connection->insert('BEXTERNALIDENTITIES', [
                'BUSERID' => $userId,
                'BSOURCE' => $source,
                'BINSTANCEID' => $instanceId,
                'BEXTERNALID' => $externalId,
                'BAPIKEYID' => $apiKeyId,
                'BMODE' => $mode,
                'BSTATUS' => ExternalIdentity::STATUS_ACTIVE,
                'BCREATED' => $now,
                'BLASTSEEN' => $now,
            ], [
                'BUSERID' => ParameterType::INTEGER,
                'BSOURCE' => ParameterType::STRING,
                'BINSTANCEID' => ParameterType::STRING,
                'BEXTERNALID' => ParameterType::STRING,
                'BAPIKEYID' => null === $apiKeyId ? ParameterType::NULL : ParameterType::INTEGER,
                'BMODE' => null === $mode ? ParameterType::NULL : ParameterType::STRING,
                'BSTATUS' => ParameterType::STRING,
                'BCREATED' => ParameterType::INTEGER,
                'BLASTSEEN' => ParameterType::INTEGER,
            ]);
            if ($nested) {
                $connection->commit();
            }
        } catch (\Throwable $e) {
            if ($nested && $connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $e;
        }
    }

    private function requireByTriple(string $source, string $instanceId, string $externalId): ExternalIdentity
    {
        $identity = $this->findOneByTriple($source, $instanceId, $externalId);
        if ($identity instanceof ExternalIdentity) {
            return $identity;
        }

        throw new \RuntimeException(sprintf('External identity "%s" / "%s" / "%s" was written but could not be read back.', $source, $instanceId, $externalId));
    }

    /**
     * A canonical row: this person, this cloud, a real instance id.
     */
    public function findCanonicalForUserSource(int $userId, string $source): ?ExternalIdentity
    {
        $row = $this->createQueryBuilder('e')
            ->where('e.userId = :userId')
            ->andWhere('e.source = :source')
            ->andWhere('e.instanceId <> :empty')
            ->setParameter('userId', $userId)
            ->setParameter('source', $source)
            ->setParameter('empty', '')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row instanceof ExternalIdentity ? $row : null;
    }

    /**
     * @return list<ExternalIdentity>
     */
    public function findForUserOnInstance(int $userId, string $source, string $instanceId): array
    {
        /** @var list<ExternalIdentity> $rows */
        $rows = $this->findBy([
            'userId' => $userId,
            'source' => $source,
            'instanceId' => $instanceId,
        ]);

        return $rows;
    }

    public function deleteByUserId(int $userId): void
    {
        $this->createQueryBuilder('e')
            ->delete()
            ->where('e.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
    }

    /**
     * @return list<ExternalIdentity>
     */
    public function findByInstanceId(string $instanceId): array
    {
        /** @var list<ExternalIdentity> $rows */
        $rows = $this->findBy(['instanceId' => $instanceId]);

        return $rows;
    }

    /**
     * @param list<int> $apiKeyIds
     *
     * @return list<ExternalIdentity>
     */
    public function findByApiKeyIds(array $apiKeyIds): array
    {
        if ([] === $apiKeyIds) {
            return [];
        }

        /** @var list<ExternalIdentity> $rows */
        $rows = $this->findBy(['apiKeyId' => $apiKeyIds]);

        return $rows;
    }

    public function findOneByApiKeyId(int $apiKeyId): ?ExternalIdentity
    {
        $row = $this->findOneBy(['apiKeyId' => $apiKeyId]);

        return $row instanceof ExternalIdentity ? $row : null;
    }

    public function remove(ExternalIdentity $identity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($identity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
