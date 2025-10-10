<?php

namespace App\Repository;

use App\Entity\Prompt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Prompt>
 */
class PromptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Prompt::class);
    }

    /**
     * Get prompt by topic
     * 
     * @param string $topic Topic identifier (e.g., 'tools:sort', 'general', 'mediamaker')
     * @param int $ownerId Owner ID (0 for system, userId for user-specific)
     * @param string $lang Language code (default 'en')
     * @return Prompt|null
     */
    public function findByTopic(string $topic, int $ownerId = 0, string $lang = 'en'): ?Prompt
    {
        return $this->createQueryBuilder('p')
            ->where('p.topic = :topic')
            ->andWhere('p.ownerId = :ownerId')
            ->andWhere('p.language = :lang')
            ->setParameter('topic', $topic)
            ->setParameter('ownerId', $ownerId)
            ->setParameter('lang', $lang)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get all available topics for sorting
     * 
     * @param int $ownerId Owner ID (0 for system)
     * @param bool $excludeTools Exclude tool topics (tools:*) from result
     * @return array Array of topic strings
     */
    public function getAllTopics(int $ownerId = 0, bool $excludeTools = true): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('DISTINCT p.topic')
            ->where('p.ownerId = :ownerId')
            ->setParameter('ownerId', $ownerId);

        if ($excludeTools) {
            $qb->andWhere('p.topic NOT LIKE :toolsPrefix')
                ->setParameter('toolsPrefix', 'tools:%');
        }

        $results = $qb->getQuery()->getScalarResult();

        return array_map(fn($r) => $r['topic'], $results);
    }

    /**
     * Get all topics with their descriptions for sorting prompt
     * 
     * @param int $ownerId Owner ID (0 for system)
     * @param string $lang Language code
     * @param bool $excludeTools Exclude tool topics (tools:*) from result
     * @return array Array of ['topic' => string, 'description' => string]
     */
    public function getTopicsWithDescriptions(int $ownerId = 0, string $lang = 'en', bool $excludeTools = true): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('p.topic', 'p.shortDescription')
            ->where('p.ownerId = :ownerId')
            ->andWhere('p.language = :lang')
            ->setParameter('ownerId', $ownerId)
            ->setParameter('lang', $lang);

        if ($excludeTools) {
            $qb->andWhere('p.topic NOT LIKE :toolsPrefix')
                ->setParameter('toolsPrefix', 'tools:%');
        }

        $prompts = $qb->getQuery()->getResult();

        return array_map(fn($p) => [
            'topic' => $p['topic'],
            'description' => $p['shortDescription']
        ], $prompts);
    }

    /**
     * Get prompt by topic with user override support
     * Tries user-specific first, then falls back to global (ownerId=0)
     * 
     * @param string $topic Topic identifier
     * @param string $lang Language code
     * @param int $userId User ID (0 = only global)
     * @return Prompt|null
     */
    public function findByTopicAndUser(string $topic, string $lang = 'en', int $userId = 0): ?Prompt
    {
        // Try user-specific first if userId > 0
        if ($userId > 0) {
            $userPrompt = $this->findByTopic($topic, $userId, $lang);
            if ($userPrompt) {
                return $userPrompt;
            }
        }

        // Fallback to global (ownerId = 0)
        return $this->findByTopic($topic, 0, $lang);
    }

    /**
     * Get all user-accessible prompts (global + user-specific)
     * 
     * @param int $userId User ID
     * @param string $lang Language code
     * @return Prompt[]
     */
    public function findAllForUser(int $userId, string $lang = 'en'): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.ownerId = 0 OR p.ownerId = :userId')
            ->andWhere('p.language = :lang')
            ->andWhere('p.topic NOT LIKE :toolsPrefix')
            ->setParameter('userId', $userId)
            ->setParameter('lang', $lang)
            ->setParameter('toolsPrefix', 'tools:%')
            ->orderBy('p.ownerId', 'DESC') // User-specific first
            ->addOrderBy('p.topic', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
