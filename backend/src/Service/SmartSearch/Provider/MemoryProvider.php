<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Provider;

use App\Service\SmartSearch\SearchHit;
use App\Service\SmartSearch\SearchProviderInterface;
use App\Service\SmartSearch\SearchRequest;
use App\Service\UserMemoryService;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * What the AI remembers about the user, by meaning. Internal feedback
 * categories stay hidden (the memory service drops them).
 */
#[AsTaggedItem(priority: 60)]
final readonly class MemoryProvider implements SearchProviderInterface
{
    public const KIND = 'memory';
    private const MIN_SCORE = 0.5;
    private const MAX_HITS = 8;
    private const TITLE_LENGTH = 90;

    public function __construct(
        private UserMemoryService $memories,
    ) {
    }

    public function name(): string
    {
        return 'memories';
    }

    public function search(SearchRequest $request): array
    {
        if (!$request->wants(self::KIND) || !$this->memories->isAvailable()) {
            return [];
        }
        $vector = $request->vectors->forMemories();
        if (null === $vector) {
            return [];
        }

        $found = $this->memories->searchMemoriesByVector(
            $request->userId(),
            $vector,
            limit: min(self::MAX_HITS, $request->limit),
            minScore: $request->vectors->memoriesMinScore(self::MIN_SCORE),
        );

        $hits = [];
        foreach ($found as $memory) {
            $id = (int) ($memory['id'] ?? 0);
            $value = trim((string) ($memory['value'] ?? ''));
            if ($id <= 0 || '' === $value) {
                continue;
            }
            $hits[] = new SearchHit(
                kind: self::KIND,
                refId: (string) $id,
                title: mb_strimwidth($value, 0, self::TITLE_LENGTH, '…'),
                route: '/memories?highlight='.$id,
                matchedBy: SearchHit::MATCHED_SEMANTIC,
                subtitle: self::subtitle($memory),
                score: (float) ($memory['score'] ?? 0.0),
            );
        }

        return $hits;
    }

    /**
     * @param array<string, mixed> $memory
     */
    private static function subtitle(array $memory): ?string
    {
        $category = trim((string) ($memory['category'] ?? ''));

        return '' === $category ? null : ucfirst(str_replace('_', ' ', $category));
    }
}
