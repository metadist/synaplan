<?php

namespace App\Repository;

use App\Entity\Message;
use App\Entity\SearchResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SearchResult>
 */
class SearchResultRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SearchResult::class);
    }

    /**
     * Get all search results for a message.
     *
     * @return SearchResult[]
     */
    public function findByMessage(Message $message): array
    {
        return $this->createQueryBuilder('sr')
            ->where('sr.message = :message')
            ->setParameter('message', $message)
            ->orderBy('sr.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Batch-load search results for multiple messages in a single query.
     *
     * @param Message[] $messages
     *
     * @return array<int, SearchResult[]> keyed by message ID
     */
    public function findByMessages(array $messages): array
    {
        if ([] === $messages) {
            return [];
        }

        $results = $this->createQueryBuilder('sr')
            ->where('sr.message IN (:messages)')
            ->setParameter('messages', $messages)
            ->orderBy('sr.position', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($results as $sr) {
            $msgId = $sr->getMessage()->getId();
            $grouped[$msgId][] = $sr;
        }

        return $grouped;
    }

    /**
     * Save search results for a message.
     *
     * @param array  $searchResults Parsed search results from BraveSearchService
     * @param string $query         The search query
     */
    public function saveSearchResults(Message $message, array $searchResults, string $query): void
    {
        $em = $this->getEntityManager();

        // Delete existing search results for this message (if any)
        $this->createQueryBuilder('sr')
            ->delete()
            ->where('sr.message = :message')
            ->setParameter('message', $message)
            ->getQuery()
            ->execute();

        // Save new search results
        foreach ($searchResults['results'] as $index => $result) {
            $searchResult = new SearchResult();
            $searchResult->setMessage($message);
            $searchResult->setQuery($query);
            $searchResult->setTitle($result['title'] ?? '');
            $searchResult->setUrl($result['url'] ?? '');
            $searchResult->setDescription($result['description'] ?? null);
            $searchResult->setPublished($result['age'] ?? null);
            $searchResult->setSource($result['profile']['name'] ?? null);
            $searchResult->setThumbnail($result['thumbnail'] ?? null);
            $searchResult->setPosition($index + 1);
            $searchResult->setExtraSnippets($result['extra_snippets'] ?? null);

            $em->persist($searchResult);
        }

        $em->flush();
    }
}
