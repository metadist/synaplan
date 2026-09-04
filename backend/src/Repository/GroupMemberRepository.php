<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GroupMember;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GroupMember>
 */
class GroupMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GroupMember::class);
    }

    public function save(GroupMember $member, bool $flush = true): void
    {
        $this->getEntityManager()->persist($member);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(GroupMember $member, bool $flush = true): void
    {
        $this->getEntityManager()->remove($member);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findMembership(int $groupId, int $userId): ?GroupMember
    {
        return $this->find(['groupId' => $groupId, 'userId' => $userId]);
    }

    /**
     * @return list<GroupMember>
     */
    public function findByGroupId(int $groupId): array
    {
        /** @var list<GroupMember> $members */
        $members = $this->findBy(['groupId' => $groupId], ['created' => 'ASC']);

        return $members;
    }

    /**
     * @return list<GroupMember>
     */
    public function findByUserId(int $userId): array
    {
        /** @var list<GroupMember> $members */
        $members = $this->findBy(['userId' => $userId]);

        return $members;
    }

    /**
     * @param list<int> $userIds
     *
     * @return list<GroupMember>
     */
    public function findByUserIds(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        /** @var list<GroupMember> $members */
        $members = $this->createQueryBuilder('m')
            ->where('m.userId IN (:ids)')
            ->setParameter('ids', $userIds)
            ->getQuery()
            ->getResult();

        return $members;
    }

    public function countByGroupId(int $groupId): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.userId)')
            ->where('m.groupId = :groupId')
            ->setParameter('groupId', $groupId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array<int, int> groupId => member count
     */
    public function countByGroupIds(array $groupIds): array
    {
        if ([] === $groupIds) {
            return [];
        }

        /** @var list<array{groupId: int|string, cnt: int|string}> $rows */
        $rows = $this->createQueryBuilder('m')
            ->select('m.groupId AS groupId, COUNT(m.userId) AS cnt')
            ->where('m.groupId IN (:ids)')
            ->setParameter('ids', $groupIds)
            ->groupBy('m.groupId')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['groupId']] = (int) $row['cnt'];
        }

        return $out;
    }

    public function deleteByGroupId(int $groupId): void
    {
        $this->createQueryBuilder('m')
            ->delete()
            ->where('m.groupId = :groupId')
            ->setParameter('groupId', $groupId)
            ->getQuery()
            ->execute();
    }

    public function deleteByUserId(int $userId): void
    {
        $this->createQueryBuilder('m')
            ->delete()
            ->where('m.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
    }
}
