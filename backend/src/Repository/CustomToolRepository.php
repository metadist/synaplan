<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CustomTool;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CustomTool>
 */
class CustomToolRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomTool::class);
    }

    /**
     * @return list<CustomTool>
     */
    public function findByOwner(int $ownerId): array
    {
        return $this->findBy(['ownerId' => $ownerId], ['updated' => 'DESC']);
    }

    /**
     * @return list<CustomTool>
     */
    public function findEnabledByOwner(int $ownerId): array
    {
        return $this->findBy(['ownerId' => $ownerId, 'enabled' => true], ['name' => 'ASC']);
    }

    public function findOneForOwner(int $id, int $ownerId): ?CustomTool
    {
        $row = $this->findOneBy(['id' => $id, 'ownerId' => $ownerId]);

        return $row instanceof CustomTool ? $row : null;
    }

    public function findOneByOwnerAndName(int $ownerId, string $name): ?CustomTool
    {
        $row = $this->findOneBy(['ownerId' => $ownerId, 'name' => $name]);

        return $row instanceof CustomTool ? $row : null;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<CustomTool>
     */
    public function findEnabledByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->createQueryBuilder('t')
            ->where('t.id IN (:ids)')
            ->andWhere('t.enabled = true')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    public function save(CustomTool $tool, bool $flush = true): void
    {
        $this->getEntityManager()->persist($tool);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(CustomTool $tool, bool $flush = true): void
    {
        $this->getEntityManager()->remove($tool);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
