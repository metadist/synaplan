<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Agent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Agent>
 */
class AgentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Agent::class);
    }

    /**
     * @return list<Agent>
     */
    public function findByOwner(int $ownerId): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.ownerId = :ownerId')
            ->setParameter('ownerId', $ownerId)
            ->orderBy('a.updated', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByIdAndOwner(int $id, int $ownerId): ?Agent
    {
        return $this->findOneBy(['id' => $id, 'ownerId' => $ownerId]);
    }

    /**
     * @return list<Agent>
     */
    public function findPublishedByOwner(int $ownerId): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.ownerId = :ownerId')
            ->andWhere('a.status = :status')
            ->andWhere('a.publishedVersionId IS NOT NULL')
            ->setParameter('ownerId', $ownerId)
            ->setParameter('status', Agent::STATUS_PUBLISHED)
            ->orderBy('a.updated', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByPromptIdAndOwner(int $promptId, int $ownerId): ?Agent
    {
        return $this->findOneBy(['promptId' => $promptId, 'ownerId' => $ownerId]);
    }

    public function findByOwnerSlugAndSource(int $ownerId, string $slug, string $source): ?Agent
    {
        $agent = $this->findOneBy(['ownerId' => $ownerId, 'slug' => $slug, 'source' => $source]);

        return $agent instanceof Agent ? $agent : null;
    }

    /**
     * @return list<Agent>
     */
    public function findByOwnerAndSource(int $ownerId, string $source): array
    {
        return $this->findBy(['ownerId' => $ownerId, 'source' => $source], ['id' => 'ASC']);
    }

    /**
     * Published assistants the router may offer. Callers must still filter
     * by {@see \App\Service\Iam\Permission::Use} for the viewing user.
     *
     * @return list<Agent>
     */
    public function findPublishedRoutable(): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.status = :status')
            ->andWhere('a.routable = 1')
            ->andWhere('a.publishedVersionId IS NOT NULL')
            ->setParameter('status', Agent::STATUS_PUBLISHED)
            ->orderBy('a.updated', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Agent>
     */
    public function findPublishedBySlug(string $slug): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.slug = :slug')
            ->andWhere('a.status = :status')
            ->andWhere('a.publishedVersionId IS NOT NULL')
            ->setParameter('slug', $slug)
            ->setParameter('status', Agent::STATUS_PUBLISHED)
            ->orderBy('a.updated', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function slugTaken(int $ownerId, string $slug, ?int $exceptId = null): bool
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.ownerId = :ownerId')
            ->andWhere('a.slug = :slug')
            ->setParameter('ownerId', $ownerId)
            ->setParameter('slug', $slug);

        if (null !== $exceptId) {
            $qb->andWhere('a.id != :exceptId')->setParameter('exceptId', $exceptId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function save(Agent $agent, bool $flush = true): void
    {
        $this->getEntityManager()->persist($agent);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Agent $agent, bool $flush = true): void
    {
        $this->getEntityManager()->remove($agent);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
