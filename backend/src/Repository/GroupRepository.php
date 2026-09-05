<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Group;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Group>
 */
class GroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Group::class);
    }

    public function save(Group $group, bool $flush = true): void
    {
        $this->getEntityManager()->persist($group);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Group $group, bool $flush = true): void
    {
        $this->getEntityManager()->remove($group);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findOneBySlug(string $slug): ?Group
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * @return list<Group>
     */
    public function findAllOrderedByName(): array
    {
        /** @var list<Group> $groups */
        $groups = $this->createQueryBuilder('g')
            ->orderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $groups;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<Group>
     */
    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<Group> $groups */
        $groups = $this->createQueryBuilder('g')
            ->where('g.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $groups;
    }
}
