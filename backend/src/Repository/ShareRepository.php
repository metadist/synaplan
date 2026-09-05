<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Share;
use App\Service\Iam\Permission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Share>
 */
class ShareRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Share::class);
    }

    public function save(Share $share, bool $flush = true): void
    {
        $this->getEntityManager()->persist($share);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Share $share, bool $flush = true): void
    {
        $this->getEntityManager()->remove($share);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return list<Share>
     */
    public function findForResource(string $kind, string $resourceId): array
    {
        /** @var list<Share> $rows */
        $rows = $this->findBy(
            ['resourceKind' => $kind, 'resourceId' => $resourceId],
            ['created' => 'ASC'],
        );

        return $rows;
    }

    public function findOneForSubject(
        string $kind,
        string $resourceId,
        string $subjectType,
        int $subjectId,
    ): ?Share {
        return $this->findOneBy([
            'resourceKind' => $kind,
            'resourceId' => $resourceId,
            'subjectType' => $subjectType,
            'subjectId' => $subjectId,
        ]);
    }

    /**
     * Shares that reach this user: themselves, any of their groups, or everyone.
     *
     * @param list<int> $groupIds
     *
     * @return list<Share>
     */
    public function findForSubjects(
        int $userId,
        array $groupIds,
        ?string $kind = null,
        ?string $resourceId = null,
    ): array {
        $qb = $this->createQueryBuilder('s');
        $or = $qb->expr()->orX(
            $qb->expr()->andX(
                's.subjectType = :userType',
                's.subjectId = :userId',
            ),
            's.subjectType = :everyoneType',
        );
        $qb->setParameter('userType', Share::SUBJECT_USER)
            ->setParameter('userId', $userId)
            ->setParameter('everyoneType', Share::SUBJECT_EVERYONE);

        if ([] !== $groupIds) {
            $or->add($qb->expr()->andX(
                's.subjectType = :groupType',
                's.subjectId IN (:groupIds)',
            ));
            $qb->setParameter('groupType', Share::SUBJECT_GROUP)
                ->setParameter('groupIds', $groupIds);
        }

        $qb->where($or);
        if (null !== $kind && '' !== $kind) {
            $qb->andWhere('s.resourceKind = :kind')->setParameter('kind', $kind);
        }
        if (null !== $resourceId && '' !== $resourceId) {
            $qb->andWhere('s.resourceId = :resourceId')->setParameter('resourceId', $resourceId);
        }

        /** @var list<Share> $rows */
        $rows = $qb->orderBy('s.created', 'ASC')->getQuery()->getResult();

        return $rows;
    }

    /**
     * Highest permission this user holds on one resource, or null if none.
     *
     * @param list<int> $groupIds
     */
    public function highestPermission(
        int $userId,
        array $groupIds,
        string $kind,
        string $resourceId,
    ): ?Permission {
        $highest = null;
        foreach ($this->findForSubjects($userId, $groupIds, $kind, $resourceId) as $share) {
            $permission = Permission::tryFrom($share->getPermission());
            if (null === $permission) {
                continue;
            }
            if (null === $highest || $permission->implies($highest)) {
                $highest = $permission;
            }
        }

        return $highest;
    }

    public function deleteByResource(string $kind, string $resourceId): void
    {
        $this->createQueryBuilder('s')
            ->delete()
            ->where('s.resourceKind = :kind')
            ->andWhere('s.resourceId = :resourceId')
            ->setParameter('kind', $kind)
            ->setParameter('resourceId', $resourceId)
            ->getQuery()
            ->execute();
    }

    public function deleteBySubjectUser(int $userId): void
    {
        $this->createQueryBuilder('s')
            ->delete()
            ->where('s.subjectType = :type')
            ->andWhere('s.subjectId = :userId')
            ->setParameter('type', Share::SUBJECT_USER)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
    }

    public function deleteBySubjectGroup(int $groupId): void
    {
        $this->createQueryBuilder('s')
            ->delete()
            ->where('s.subjectType = :type')
            ->andWhere('s.subjectId = :groupId')
            ->setParameter('type', Share::SUBJECT_GROUP)
            ->setParameter('groupId', $groupId)
            ->getQuery()
            ->execute();
    }

    public function deleteByGrantedBy(int $userId): void
    {
        $this->createQueryBuilder('s')
            ->delete()
            ->where('s.grantedBy = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
    }

    public function deleteByOwnerKnowledgeFolders(int $ownerId): void
    {
        $prefix = $ownerId.':';
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix);

        $this->createQueryBuilder('s')
            ->delete()
            ->where('s.resourceKind = :kind')
            ->andWhere("s.resourceId LIKE :prefix ESCAPE '!'")
            ->setParameter('kind', 'knowledge_folder')
            ->setParameter('prefix', $escaped.'%')
            ->getQuery()
            ->execute();
    }
}
