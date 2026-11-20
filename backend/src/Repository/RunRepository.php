<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Run;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Run>
 */
class RunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Run::class);
    }

    public function findOneByUuid(string $uuid): ?Run
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    public function save(Run $run): void
    {
        $this->getEntityManager()->persist($run);
        $this->getEntityManager()->flush();
    }

    /** One run, only when it belongs to $userId. */
    public function findOneForUser(string $uuid, int $userId): ?Run
    {
        return $this->findOneBy(['uuid' => $uuid, 'userId' => $userId]);
    }

    /**
     * @return list<string>
     */
    public function uuidsForUser(int $userId): array
    {
        /** @var list<array{uuid: string}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('r.uuid')
            ->where('r.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => $row['uuid'], $rows);
    }

    public function deleteForUser(int $userId): int
    {
        return (int) $this->createQueryBuilder('r')
            ->delete()
            ->where('r.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
    }

    /** Runs in other accounts that $userId started keep running, without the starter's id. */
    public function clearStartedBy(int $userId): int
    {
        return (int) $this->createQueryBuilder('r')
            ->update()
            ->set('r.startedBy', ':none')
            ->where('r.startedBy = :userId')
            ->setParameter('none', null)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
    }
}
