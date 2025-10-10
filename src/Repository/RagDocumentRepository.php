<?php

namespace App\Repository;

use App\Entity\RagDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RagDocument>
 */
class RagDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RagDocument::class);
    }

    /**
     * Findet RAG-Dokumente für einen User
     */
    public function findByUser(int $userId, int $limit = 100): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.userId = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('r.created', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Findet RAG-Dokumente nach GroupKey
     */
    public function findByGroupKey(string $groupKey): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.groupKey = :groupKey')
            ->setParameter('groupKey', $groupKey)
            ->orderBy('r.startLine', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Vector-Search (Similarity Search)
     * 
     * Hinweis: Für MariaDB 11.7+ mit VECTOR-Type würde man hier
     * VEC_DISTANCE verwenden. Aktuell als JSON gespeichert.
     */
    public function searchSimilar(int $userId, array $queryVector, float $threshold = 0.3, int $limit = 10): array
    {
        // Simplified version - in production würde man hier
        // MariaDB's VEC_DISTANCE Function nutzen mit Custom DQL Function
        
        return $this->createQueryBuilder('r')
            ->where('r.userId = :userId')
            ->setParameter('userId', $userId)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
        
        // TODO: Implement with Custom DQL Function VEC_DISTANCE
        // SELECT * FROM BRAG 
        // WHERE BUID = :userId 
        // AND VEC_DISTANCE(BEMBED, VEC_FromText(:vector)) < :threshold
        // ORDER BY VEC_DISTANCE(BEMBED, VEC_FromText(:vector)) ASC
        // LIMIT :limit
    }

    /**
     * Löscht RAG-Dokumente nach GroupKey
     */
    public function deleteByGroupKey(string $groupKey): int
    {
        return $this->createQueryBuilder('r')
            ->delete()
            ->where('r.groupKey = :groupKey')
            ->setParameter('groupKey', $groupKey)
            ->getQuery()
            ->execute();
    }

    /**
     * Speichert RAG-Dokument
     */
    public function save(RagDocument $ragDocument, bool $flush = true): void
    {
        $this->getEntityManager()->persist($ragDocument);
        
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Löscht RAG-Dokument
     */
    public function remove(RagDocument $ragDocument, bool $flush = true): void
    {
        $this->getEntityManager()->remove($ragDocument);
        
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}

