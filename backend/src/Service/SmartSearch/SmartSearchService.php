<?php

declare(strict_types=1);

namespace App\Service\SmartSearch;

use App\Entity\RevectorizeRun;
use App\Entity\User;
use App\Repository\RevectorizeRunRepository;
use App\Service\SmartSearch\Index\BackfillTracker;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Global search behind the Ctrl/Cmd+K palette: asks every provider (keyword
 * tiers first, meaning tiers after), fuses the ranked lists with RRF and
 * reports what was skipped instead of failing.
 */
final readonly class SmartSearchService
{
    /** Kinds the API accepts in `kinds`; the local palette owns pages and commands. */
    public const KINDS = ['chat', 'file', 'widget', 'assistant', 'task', 'memory', 'setting'];
    public const MAX_LIMIT = 50;
    public const DEFAULT_LIMIT = 20;
    public const MAX_QUERY_LENGTH = 200;

    /**
     * Once the whole search has spent this long, remaining providers are
     * skipped. Covers one cold query embed; keyword tiers run first.
     */
    private const TIME_BUDGET_MS = 1500;

    /** @var list<SearchProviderInterface> */
    private array $providers;

    /**
     * @param iterable<SearchProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('app.smart_search.provider')]
        iterable $providers,
        private BackfillTracker $backfill,
        private QueryVectorsFactory $vectors,
        private RevectorizeRunRepository $runs,
        private LoggerInterface $logger,
    ) {
        $this->providers = array_values([...$providers]);
    }

    /**
     * @param list<string>|null $kinds null = every kind
     * @param int               $rrfK  fusion constant; only `app:search:eval` changes it
     */
    public function search(User $user, string $query, ?array $kinds = null, int $limit = self::DEFAULT_LIMIT, int $rrfK = RankFusion::DEFAULT_K): SearchResponse
    {
        $query = mb_substr(trim($query), 0, self::MAX_QUERY_LENGTH);
        $kinds = null === $kinds ? self::KINDS : array_values(array_intersect(self::KINDS, $kinds));
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $userId = (int) $user->getId();
        $request = new SearchRequest($user, $query, $kinds, $limit, $this->vectors->create($user, $query));
        $indexing = $this->backfill->ensure($userId) || $this->reembedding();

        $lists = [];
        $degraded = [];
        $started = hrtime(true);
        foreach ($this->providers as $provider) {
            if ($this->elapsedMs($started) > self::TIME_BUDGET_MS) {
                $degraded[] = $provider->name();
                continue;
            }
            try {
                $lists[] = $provider->search($request);
            } catch (\Throwable $e) {
                $degraded[] = $provider->name();
                $this->logger->warning('Smart Search provider failed', [
                    'provider' => $provider->name(),
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return new SearchResponse(
            query: $query,
            hits: array_slice(RankFusion::fuse($lists, $rrfK), 0, $limit),
            semanticAvailable: $request->vectors->indexAvailable() && !in_array('semantic', $degraded, true),
            degraded: $degraded,
            indexing: $indexing,
        );
    }

    /** A search model switch re-embeds every row; meaning matches are partial until it ends. */
    private function reembedding(): bool
    {
        return RevectorizeRun::SCOPE_SEARCH === $this->runs->findActive()?->getScope();
    }

    private function elapsedMs(int $started): float
    {
        return (hrtime(true) - $started) / 1_000_000;
    }
}
