<?php

namespace App\Repository;

use App\Entity\PromptMeta;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PromptMeta>
 */
class PromptMetaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PromptMeta::class);
    }

    public function findByPrompt(int $promptId): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.promptId = :promptId')
            ->setParameter('promptId', $promptId)
            ->getQuery()
            ->getResult();
    }

    public function findByPromptAndKey(int $promptId, string $key): ?PromptMeta
    {
        return $this->findOneBy(['promptId' => $promptId, 'metaKey' => $key]);
    }

    public function save(PromptMeta $promptMeta, bool $flush = true): void
    {
        $this->getEntityManager()->persist($promptMeta);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(PromptMeta $promptMeta, bool $flush = true): void
    {
        $this->getEntityManager()->remove($promptMeta);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
