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
     * Get prompt by topic.
     *
     * @param string $topic   Topic identifier (e.g., 'tools:sort', 'general', 'mediamaker')
     * @param int    $ownerId Owner ID (0 for system, userId for user-specific)
     */
    public function findByTopic(string $topic, int $ownerId = 0): ?Prompt
    {
        return $this->createQueryBuilder('p')
            ->where('p.topic = :topic')
            ->andWhere('p.ownerId = :ownerId')
            ->setParameter('topic', $topic)
            ->setParameter('ownerId', $ownerId)
            ->orderBy('p.id', 'DESC') // Always get the newest prompt
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get all available topics for sorting
     * Includes both system (ownerId=0) AND user-specific prompts.
     *
     * @param int      $ownerId      Owner ID (0 for system only)
     * @param int|null $userId       User ID for including user-specific prompts
     * @param bool     $excludeTools Exclude tool topics (tools:*) from result
     *
     * @return array Array of topic strings
     */
    public function getAllTopics(int $ownerId = 0, ?int $userId = null, bool $excludeTools = true): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('DISTINCT p.topic');

        if (null !== $userId && $userId > 0) {
            // Include both system prompts AND user-specific prompts
            $qb->where('p.ownerId = 0 OR p.ownerId = :userId')
                ->setParameter('userId', $userId);
        } else {
            // Only system prompts
            $qb->where('p.ownerId = :ownerId')
            ->setParameter('ownerId', $ownerId);
        }

        if ($excludeTools) {
            $qb->andWhere('p.topic NOT LIKE :toolsPrefix')
                ->setParameter('toolsPrefix', 'tools:%');
        }

        $results = $qb->getQuery()->getScalarResult();

        return array_map(fn ($r) => $r['topic'], $results);
    }

    /**
     * Get all topics with their descriptions for sorting prompt
     * Includes both system (ownerId=0) AND user-specific prompts.
     *
     * @param int      $ownerId      Owner ID (0 for system)
     * @param string   $lang         Language code
     * @param int|null $userId       User ID for including user-specific prompts
     * @param bool     $excludeTools Exclude tool topics (tools:*) from result
     *
     * @return array Array of ['topic' => string, 'description' => string]
     */
    public function getTopicsWithDescriptions(int $ownerId = 0, string $lang = 'en', ?int $userId = null, bool $excludeTools = true): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('p.topic', 'p.shortDescription');

        if (null !== $userId && $userId > 0) {
            // Include both system prompts AND user-specific prompts
            // For user prompts, prioritize user's own prompts over system prompts
            $qb->where('(p.ownerId = 0 OR p.ownerId = :userId)')
                ->andWhere('p.language = :lang')
                ->setParameter('userId', $userId)
                ->setParameter('lang', $lang)
                ->orderBy('p.ownerId', 'DESC'); // User prompts first
        } else {
            // Only system prompts
            $qb->where('p.ownerId = :ownerId')
            ->andWhere('p.language = :lang')
            ->setParameter('ownerId', $ownerId)
            ->setParameter('lang', $lang);
        }

        if ($excludeTools) {
            $qb->andWhere('p.topic NOT LIKE :toolsPrefix')
                ->setParameter('toolsPrefix', 'tools:%');
        }

        $prompts = $qb->getQuery()->getResult();

        // Remove duplicates, keeping user-specific prompts over system prompts
        $seen = [];
        $result = [];
        foreach ($prompts as $p) {
            if (!isset($seen[$p['topic']])) {
                $result[] = [
                    'topic' => $p['topic'],
                    'description' => $p['shortDescription'],
                ];
                $seen[$p['topic']] = true;
            }
        }

        return $result;
    }

    /**
     * Get prompt by topic with user override support
     * Tries user-specific first, then falls back to global (ownerId=0)
     * Language is NOT used as a filter - it's just metadata in the DB.
     *
     * @param string $topic  Topic identifier
     * @param int    $userId User ID (0 = only global)
     */
    public function findByTopicAndUser(string $topic, int $userId = 0): ?Prompt
    {
        // Try user-specific first if userId > 0
        if ($userId > 0) {
            $userPrompt = $this->findByTopic($topic, $userId);
            if ($userPrompt) {
                return $userPrompt;
            }
        }

        // Fallback to global (ownerId = 0)
        return $this->findByTopic($topic, 0);
    }

    /**
     * Get all user-accessible prompts (global + user-specific) WITH selection rules.
     *
     * @param int    $userId User ID
     * @param string $lang   Language code
     *
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

    /**
     * Get prompts with selection rules for automatic routing during sorting
     * Returns prompts that have BSELECTION_RULES defined.
     *
     * @param int    $userId User ID
     * @param string $lang   Language code
     *
     * @return Prompt[]
     */
    public function findPromptsWithSelectionRules(int $userId, string $lang = 'en'): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.ownerId = 0 OR p.ownerId = :userId')
            ->andWhere('p.language = :lang')
            ->andWhere('p.topic NOT LIKE :toolsPrefix')
            ->andWhere('p.selectionRules IS NOT NULL')
            ->andWhere('p.selectionRules != :empty')
            ->setParameter('userId', $userId)
            ->setParameter('lang', $lang)
            ->setParameter('toolsPrefix', 'tools:%')
            ->setParameter('empty', '')
            ->orderBy('p.ownerId', 'DESC') // User-specific prompts take priority
            ->getQuery()
            ->getResult();
    }
}
