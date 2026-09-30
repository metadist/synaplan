<?php

declare(strict_types=1);

namespace App\Service\SmartSearch;

use App\Entity\User;
use App\Service\SmartSearch\Index\BackfillTracker;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Global search behind the Ctrl/Cmd+K palette: asks every provider, fuses
 * the ranked lists with RRF and reports what was skipped instead of failing.
 */
final readonly class SmartSearchService
{
    /** Kinds the API accepts in `kinds`; the local palette owns pages and commands. */
    public const KINDS = ['chat', 'file', 'widget', 'assistant', 'task', 'setting'];
    public const MAX_LIMIT = 50;
    public const DEFAULT_LIMIT = 20;
    public const MAX_QUERY_LENGTH = 200;

    /** Once the whole search has spent this long, remaining providers are skipped. */
    private const TIME_BUDGET_MS = 800;

    /** @var list<SearchProviderInterface> */
    private array $providers;

    /**
     * @param iterable<SearchProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('app.smart_search.provider')]
        iterable $providers,
        private BackfillTracker $backfill,
        private LoggerInterface $logger,
    ) {
        $this->providers = array_values([...$providers]);
    }

    /**
     * @param list<string>|null $kinds null = every kind
     */
    public function search(User $user, string $query, ?array $kinds = null, int $limit = self::DEFAULT_LIMIT): SearchResponse
    {
        $query = mb_substr(trim($query), 0, self::MAX_QUERY_LENGTH);
        $kinds = null === $kinds ? self::KINDS : array_values(array_intersect(self::KINDS, $kinds));
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $request = new SearchRequest($user, $query, $kinds, $limit);
        $indexing = $this->backfill->ensure($request->userId());

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
                    'user_id' => $request->userId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return new SearchResponse(
            query: $query,
            hits: array_slice(RankFusion::fuse($lists), 0, $limit),
            semanticAvailable: false,
            degraded: $degraded,
            indexing: $indexing,
        );
    }

    private function elapsedMs(int $started): float
    {
        return (hrtime(true) - $started) / 1_000_000;
    }
}
