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
     * Finds RAG documents for a user.
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
     * Finds RAG documents by GroupKey.
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
     * Vector-Search (Similarity Search).
     *
     * Note: For MariaDB 11.7+ with VECTOR-Type, VEC_DISTANCE would be used here.
     * Currently stored as JSON.
     */
    public function searchSimilar(int $userId, array $queryVector, float $threshold = 0.3, int $limit = 10): array
    {
        // Simplified version - in production, MariaDB's VEC_DISTANCE Function would be used with Custom DQL Function

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
     * Deletes RAG documents by GroupKey.
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
     * Deletes RAG documents by MessageId/FileId.
     *
     * BMID can refer to either BMESSAGES.BID or BFILES.BID.
     */
    public function deleteByMessageId(int $messageId): int
    {
        return $this->createQueryBuilder('r')
            ->delete()
            ->where('r.messageId = :messageId')
            ->setParameter('messageId', $messageId)
            ->getQuery()
            ->execute();
    }

    /**
     * Returns all distinct GroupKeys for a user with file counts.
     *
     * Only RAG documents with existing files are counted (orphaned RAG documents are ignored).
     * BMID can refer to BFILES.BID (for standalone files) or BMESSAGES.BID (for message attachments).
     *
     * @return array Array of ['groupKey' => string, 'count' => int]
     */
    public function findDistinctGroupKeysByUser(int $userId): array
    {
        $conn = $this->getEntityManager()->getConnection();

        // SQL Query: Group by BGROUPKEY and count distinct BMID (files)
        // UNION Query to consider both BFILES and BMESSAGES
        // BMID can refer to BFILES.BID (for standalone files) or BMESSAGES.BID (for message attachments)
        $sql = '
            SELECT
                r.BGROUPKEY as groupKey,
                COUNT(DISTINCT r.BMID) as count
            FROM BRAG r
            WHERE r.BUID = :userId
                AND (
                    r.BMID IN (SELECT BID FROM BFILES WHERE BUID = :userId)
                    OR r.BMID IN (SELECT BID FROM BMESSAGES WHERE BUID = :userId)
                )
            GROUP BY r.BGROUPKEY
            ORDER BY r.BGROUPKEY ASC
        ';

        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery(['userId' => $userId]);

        return $result->fetchAllAssociative();
    }

    /**
     * Saves RAG document.
     */
    public function save(RagDocument $ragDocument, bool $flush = true): void
    {
        $this->getEntityManager()->persist($ragDocument);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Removes RAG document.
     */
    public function remove(RagDocument $ragDocument, bool $flush = true): void
    {
        $this->getEntityManager()->remove($ragDocument);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
