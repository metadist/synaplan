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
     * Get all topics with their descriptions for sorting prompt.
     * System prompts (ownerId=0) are NOT filtered by language -- always included.
     * User prompts are filtered by language.
     *
     * @param int      $ownerId      Owner ID (0 for system)
     * @param string   $lang         Language code (applied to user prompts only)
     * @param int|null $userId       User ID for including user-specific prompts
     * @param bool     $excludeTools Exclude tool topics (tools:*) from result
     *
     * @return array Array of ['topic' => string, 'description' => string]
     */
    public function getTopicsWithDescriptions(int $ownerId = 0, string $lang = 'en', ?int $userId = null, bool $excludeTools = true): array
    {
        // System/owner prompts: always included regardless of language
        $sysQb = $this->createQueryBuilder('p')
            ->select('p.topic', 'p.shortDescription', 'p.ownerId')
            ->where('p.ownerId = :ownerId')
            ->setParameter('ownerId', $ownerId);

        if ($excludeTools) {
            $sysQb->andWhere('p.topic NOT LIKE :toolsPrefix')
                ->setParameter('toolsPrefix', 'tools:%');
        }

        $systemPrompts = $sysQb->getQuery()->getResult();

        // User prompts: filtered by language (unless $lang is empty, then include all)
        $userPrompts = [];
        if (null !== $userId && $userId > 0) {
            $userQb = $this->createQueryBuilder('p')
                ->select('p.topic', 'p.shortDescription', 'p.ownerId')
                ->where('p.ownerId = :userId')
                ->setParameter('userId', $userId);

            if ('' !== $lang) {
                $userQb->andWhere('p.language = :lang')
                    ->setParameter('lang', $lang);
            }

            if ($excludeTools) {
                $userQb->andWhere('p.topic NOT LIKE :toolsPrefix')
                    ->setParameter('toolsPrefix', 'tools:%');
            }

            $userPrompts = $userQb->getQuery()->getResult();
        }

        // Merge: user prompts override system prompts for the same topic
        $seen = [];
        $result = [];

        // Add user prompts first (they take priority)
        foreach ($userPrompts as $p) {
            if (!isset($seen[$p['topic']])) {
                $result[] = [
                    'topic' => $p['topic'],
                    'description' => $p['shortDescription'],
                ];
                $seen[$p['topic']] = true;
            }
        }

        // Then add system prompts (only if not already overridden)
        foreach ($systemPrompts as $p) {
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
     * Get all user-accessible prompts (global + user-specific).
     * System prompts (ownerId=0) are NOT filtered by language -- they are always included.
     * User prompts are filtered by language so only the user's current-language prompts appear.
     *
     * @param int    $userId User ID
     * @param string $lang   Language code (applied to user prompts only)
     *
     * @return Prompt[]
     */
    public function findAllForUser(int $userId, string $lang = 'en'): array
    {
        // System prompts: always included regardless of language
        $systemPrompts = $this->createQueryBuilder('p')
            ->where('p.ownerId = 0')
            ->andWhere('p.topic NOT LIKE :toolsPrefix')
            ->setParameter('toolsPrefix', 'tools:%')
            ->orderBy('p.topic', 'ASC')
            ->getQuery()
            ->getResult();

        // User prompts: filtered by language
        $userPrompts = $this->createQueryBuilder('p')
            ->where('p.ownerId = :userId')
            ->andWhere('p.language = :lang')
            ->andWhere('p.topic NOT LIKE :toolsPrefix')
            ->setParameter('userId', $userId)
            ->setParameter('lang', $lang)
            ->setParameter('toolsPrefix', 'tools:%')
            ->orderBy('p.topic', 'ASC')
            ->getQuery()
            ->getResult();

        // Merge: user prompts override system prompts for the same topic
        $map = [];
        foreach ($systemPrompts as $p) {
            $map[$p->getTopic()] = $p;
        }
        foreach ($userPrompts as $p) {
            $map[$p->getTopic()] = $p;
        }

        return array_values($map);
    }

    /**
     * Get prompts with selection rules for automatic routing during sorting.
     * System prompts (ownerId=0) are NOT filtered by language -- always included.
     * User prompts are filtered by language.
     *
     * @param int    $userId User ID
     * @param string $lang   Language code (applied to user prompts only)
     *
     * @return Prompt[]
     */
    public function findPromptsWithSelectionRules(int $userId, string $lang = 'en'): array
    {
        // System prompts: always included regardless of language
        $systemPrompts = $this->createQueryBuilder('p')
            ->where('p.ownerId = 0')
            ->andWhere('p.topic NOT LIKE :toolsPrefix')
            ->andWhere('p.selectionRules IS NOT NULL')
            ->andWhere('p.selectionRules != :empty')
            ->setParameter('toolsPrefix', 'tools:%')
            ->setParameter('empty', '')
            ->getQuery()
            ->getResult();

        // User prompts: filtered by language (unless $lang is empty, then include all)
        $userQb = $this->createQueryBuilder('p')
            ->where('p.ownerId = :userId')
            ->andWhere('p.topic NOT LIKE :toolsPrefix')
            ->andWhere('p.selectionRules IS NOT NULL')
            ->andWhere('p.selectionRules != :empty')
            ->setParameter('userId', $userId)
            ->setParameter('toolsPrefix', 'tools:%')
            ->setParameter('empty', '');

        if ('' !== $lang) {
            $userQb->andWhere('p.language = :lang')
                ->setParameter('lang', $lang);
        }

        $userPrompts = $userQb
            ->getQuery()
            ->getResult();

        // Merge: user prompts override system prompts for the same topic
        $map = [];
        foreach ($systemPrompts as $p) {
            $map[$p->getTopic()] = $p;
        }
        foreach ($userPrompts as $p) {
            $map[$p->getTopic()] = $p;
        }

        return array_values($map);
    }
}
