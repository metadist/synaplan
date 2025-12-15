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
     * Findet RAG-Dokumente für einen User.
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
     * Findet RAG-Dokumente nach GroupKey.
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
     * Löscht RAG-Dokumente nach GroupKey.
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
     * Löscht RAG-Dokumente nach MessageId/FileId.
     *
     * BMID kann sowohl auf BMESSAGES.BID als auch auf BFILES.BID verweisen.
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
     * Gibt alle eindeutigen GroupKeys für einen User zurück mit Anzahl der Dateien.
     *
     * Nur RAG-Dokumente mit existierenden Dateien werden gezählt (verwaiste RAG-Dokumente werden ignoriert).
     * BMID kann auf BFILES.BID (für standalone files) oder BMESSAGES.BID (für message attachments) verweisen.
     *
     * @return array Array von ['groupKey' => string, 'count' => int]
     */
    public function findDistinctGroupKeysByUser(int $userId): array
    {
        $conn = $this->getEntityManager()->getConnection();

        // SQL Query: Gruppiere nach BGROUPKEY und zähle eindeutige BMID (Dateien)
        // UNION Query um sowohl BFILES als auch BMESSAGES zu berücksichtigen
        // BMID kann auf BFILES.BID (für standalone files) oder BMESSAGES.BID (für message attachments) verweisen
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
     * Speichert RAG-Dokument.
     */
    public function save(RagDocument $ragDocument, bool $flush = true): void
    {
        $this->getEntityManager()->persist($ragDocument);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Löscht RAG-Dokument.
     */
    public function remove(RagDocument $ragDocument, bool $flush = true): void
    {
        $this->getEntityManager()->remove($ragDocument);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
