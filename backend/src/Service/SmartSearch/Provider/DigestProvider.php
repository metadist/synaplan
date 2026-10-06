<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Provider;

use App\Service\Digest\DigestSearchService;
use App\Service\Digest\MessageDigestConfig;
use App\Service\SmartSearch\Index\SearchIndexer;
use App\Service\SmartSearch\SearchHit;
use App\Service\SmartSearch\SearchProviderInterface;
use App\Service\SmartSearch\SearchRequest;
use App\Service\UserMemoryService;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Deep chat recall: the message digests find a chat by what was said in it
 * ("the letter about the office rent"), not only by its title.
 */
#[AsTaggedItem(priority: 50)]
final readonly class DigestProvider implements SearchProviderInterface
{
    private const KIND = 'chat';

    public function __construct(
        private DigestSearchService $digests,
        private MessageDigestConfig $config,
        private SearchIndexer $indexer,
        private UserMemoryService $memories,
    ) {
    }

    public function name(): string
    {
        return 'digests';
    }

    public function search(SearchRequest $request): array
    {
        $userId = $request->userId();
        $source = $this->indexer->source(self::KIND);
        // Digests live in Qdrant next to the memories.
        if (!$request->wants(self::KIND) || null === $source || !$this->config->isEnabled() || !$this->memories->isAvailable()) {
            return [];
        }
        $vector = $request->vectors->forMemories();
        if (null === $vector) {
            return [];
        }

        // The digest config keeps its own floor; this only adds the adaptive
        // cutoff when digests share the index space.
        $minScore = $request->vectors->memoriesMinScore(0.0);
        $best = [];
        // Smart search is not a chat turn, so there is no verbatim history
        // window: digests of every chat, including the one on screen, stay findable.
        foreach ($this->digests->search($userId, $vector, excludeMessageIds: []) as $digest) {
            $chatId = (string) $digest['chat_id'];
            if ('0' !== $chatId && !isset($best[$chatId]) && $digest['score'] >= $minScore) {
                $best[$chatId] = $digest;
            }
        }
        if ([] === $best) {
            return [];
        }

        $resolved = $source->resolve($userId, array_map('strval', array_keys($best)));
        $hits = [];
        foreach ($best as $chatId => $digest) {
            $chatId = (string) $chatId;
            $item = $resolved[$chatId] ?? null;
            if (null === $item) {
                continue;
            }
            $hits[] = new SearchHit(
                kind: self::KIND,
                refId: $chatId,
                title: '' !== $item->title ? $item->title : $digest['title'],
                route: $item->route,
                matchedBy: SearchHit::MATCHED_SEMANTIC,
                subtitle: $item->subtitle,
                snippet: $digest['title'],
                score: $digest['effective_score'],
            );
        }

        return $hits;
    }
}
