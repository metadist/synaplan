<?php

declare(strict_types=1);

namespace App\Plug\Rerank;

use App\Plug\PlugConfigService;

/**
 * Reorders RAG hits when a rerank adapter is active. On timeout, empty
 * output or exception: keep embedding order and cut to $k.
 *
 * @phpstan-type RagHit array<string, mixed>
 */
final readonly class RerankStage
{
    public function __construct(
        private RerankRegistry $registry,
        private PlugConfigService $config,
        private RerankMetrics $metrics,
    ) {
    }

    /**
     * Storage limit: k when rerank is off, min(k × multiplier, 100) when on
     * and a query string is available.
     */
    public function storageLimit(int $k, ?string $queryText): int
    {
        if ($k < 1) {
            return $k;
        }
        if (null === $this->registry->active() || null === $queryText || '' === trim($queryText)) {
            return $k;
        }

        $multiplied = $k * $this->config->rerankCandidatesMultiplier();

        return min($multiplied, PlugConfigService::MAX_RERANK_STORAGE_CANDIDATES);
    }

    /**
     * @param list<RagHit> $results
     *
     * @return list<RagHit>
     */
    public function apply(string $query, array $results, int $k): array
    {
        $provider = $this->registry->active();
        if (null === $provider || [] === $results || $k < 1 || '' === trim($query)) {
            return $results;
        }

        $candidates = [];
        foreach ($results as $i => $row) {
            $text = \is_string($row['chunk_text'] ?? null) ? $row['chunk_text'] : '';
            $id = $this->candidateId($row, $i);
            $score = is_numeric($row['score'] ?? null) ? (float) $row['score'] : 0.0;
            $candidates[] = new RerankCandidate($id, $text, $score);
        }

        $options = new RerankOptions(
            latencyBudgetMs: $this->config->rerankLatencyBudgetMs(),
            maxCandidateChars: $this->config->rerankMaxCandidateChars(),
        );

        $started = hrtime(true);
        try {
            $ranked = $provider->rerank($query, $candidates, $k, $options);
            $ms = $ranked->ms > 0 ? $ranked->ms : (int) ((hrtime(true) - $started) / 1_000_000);
            $this->metrics->observeLatency($ms);
            if ($ms > $options->latencyBudgetMs) {
                return $this->fallback($results, $k, 'timeout');
            }
            if ([] === $ranked->hits) {
                return $this->fallback($results, $k, 'empty');
            }

            return $this->reorder($results, $ranked, $k);
        } catch (\Throwable) {
            $ms = (int) ((hrtime(true) - $started) / 1_000_000);
            $this->metrics->observeLatency($ms);

            return $this->fallback($results, $k, 'exception');
        }
    }

    /**
     * @param list<RagHit> $results
     *
     * @return list<RagHit>
     */
    private function fallback(array $results, int $k, string $reason): array
    {
        $this->metrics->incrementFallback($reason);
        $cut = \array_slice($results, 0, $k);
        $meta = ['applied' => false, 'reason' => $reason];
        foreach ($cut as $i => $row) {
            $cut[$i]['rerank'] = $meta;
        }

        return $cut;
    }

    /**
     * Ranked hits first; when the provider returned fewer than $k usable ids
     * (LLM dropped some, HTTP top_n was short), the remaining rows follow in
     * embedding order so rerank never yields fewer results than rerank-off.
     *
     * @param list<RagHit> $results
     *
     * @return list<RagHit>
     */
    private function reorder(array $results, RerankResult $ranked, int $k): array
    {
        $byId = [];
        foreach ($results as $i => $row) {
            $byId[$this->candidateId($row, $i)] = $row;
        }

        $out = [];
        $meta = [
            'applied' => true,
            'provider' => $ranked->provider,
            'ms' => $ranked->ms,
        ];
        foreach ($ranked->hits as $hit) {
            $id = $hit['id'];
            if (!isset($byId[$id])) {
                continue;
            }
            $row = $byId[$id];
            $row['rerank_score'] = $hit['score'];
            $row['rerank'] = $meta;
            $out[] = $row;
            unset($byId[$id]);
            if (count($out) >= $k) {
                break;
            }
        }

        foreach ($byId as $row) {
            if (count($out) >= $k) {
                break;
            }
            $row['rerank'] = $meta + ['backfilled' => true];
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param RagHit $row
     */
    private function candidateId(array $row, int $index): string
    {
        if (isset($row['chunk_id']) && (is_numeric($row['chunk_id']) || \is_string($row['chunk_id']))) {
            return (string) $row['chunk_id'];
        }

        return 'idx-'.$index;
    }
}
