<?php

declare(strict_types=1);

namespace App\Plug\Rerank;

use App\Entity\Model;
use App\Model\ModelCatalog;
use App\Plug\PlugConfigService;
use App\Plug\PlugKeyStore;
use App\Plug\Rerank\Adapter\HttpRerankAdapter;
use App\Plug\Rerank\Adapter\LlmReranker;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Service\ModelConfigService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Status, save and Test for the admin Reranking tab.
 */
final readonly class RerankAdminService
{
    public function __construct(
        private RerankRegistry $registry,
        private PlugConfigService $plugConfig,
        private ModelConfigService $modelConfig,
        private ModelRepository $models,
        private ConfigRepository $config,
        private PlugKeyStore $keys,
        private HttpRerankAdapter $http,
        private LlmReranker $llm,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $bound = $this->http->resolveModel(null);

        return [
            'enabled' => $this->plugConfig->isRerankEnabled(),
            'modelKey' => null !== $bound ? RerankCatalog::keyForModel($bound) : null,
            'multiplier' => $this->plugConfig->rerankCandidatesMultiplier(),
            'budgetMs' => $this->plugConfig->rerankLatencyBudgetMs(),
            'llmFallback' => $this->plugConfig->isRerankLlmFallback(),
            'adapters' => $this->adapters(),
            'models' => $this->models(),
            'keys' => $this->keyStatuses(),
            'lastEval' => $this->plugConfig->lastRerankEval(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function save(bool $enabled, ?string $modelKey, int $multiplier, int $budgetMs, bool $llmFallback): array
    {
        $normalized = null !== $modelKey ? strtolower(trim($modelKey)) : '';
        if ($enabled && '' === $normalized && !$llmFallback) {
            throw new \InvalidArgumentException('A rerank model is required when rerank is enabled');
        }

        if ('' !== $normalized) {
            $model = $this->resolveOrMaterialize($normalized);
            if (null === $model) {
                throw new \InvalidArgumentException('Unknown rerank model: '.$normalized);
            }
            $this->config->setValue(0, 'DEFAULTMODEL', 'RERANK', (string) $model->getId());
            $this->modelConfig->invalidateUsableProviders();
        } else {
            $this->config->deleteValue(0, 'DEFAULTMODEL', 'RERANK');
        }

        $this->plugConfig->setRerank($enabled, $multiplier, $budgetMs, $llmFallback);

        return $this->status();
    }

    /**
     * @param list<string> $documents
     *
     * @return array{ordered: list<array{index: int, score: float}>, provider: string, ms: int, error: ?string}
     */
    public function test(string $query, array $documents, ?string $modelKey = null): array
    {
        $docs = [];
        foreach ($documents as $text) {
            $trimmed = trim($text);
            if ('' !== $trimmed) {
                $docs[] = $trimmed;
            }
            if (count($docs) >= 20) {
                break;
            }
        }
        if ('' === trim($query) || [] === $docs) {
            throw new \InvalidArgumentException('query and at least one document are required');
        }

        $candidates = [];
        foreach ($docs as $i => $text) {
            $candidates[] = new RerankCandidate((string) $i, $text, 0.0);
        }

        $options = new RerankOptions(
            latencyBudgetMs: $this->plugConfig->rerankLatencyBudgetMs(),
            maxCandidateChars: $this->plugConfig->rerankMaxCandidateChars(),
            modelKey: $modelKey,
        );

        $started = hrtime(true);
        try {
            $result = $this->runTest($query, $candidates, $options);
            $ordered = [];
            foreach ($result->hits as $hit) {
                $ordered[] = [
                    'index' => (int) $hit['id'],
                    'score' => $hit['score'],
                ];
            }

            return [
                'ordered' => $ordered,
                'provider' => $result->provider,
                'ms' => $result->ms > 0 ? $result->ms : (int) ((hrtime(true) - $started) / 1_000_000),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'ordered' => [],
                'provider' => HttpRerankAdapter::KEY,
                'ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'error' => $e->getMessage(),
            ];
        }
    }

    private function resolveOrMaterialize(string $modelKey): ?Model
    {
        $model = $this->http->resolveModel($modelKey);
        if (null !== $model) {
            return $model;
        }

        $bid = RerankCatalog::bidByKey($modelKey);
        if (null === $bid) {
            return null;
        }

        foreach (ModelCatalog::all() as $row) {
            if ((int) $row['id'] !== $bid || 'rerank' !== ($row['tag'] ?? '')) {
                continue;
            }
            ModelCatalog::upsert($this->em->getConnection(), $row);

            return $this->models->find($bid);
        }

        return null;
    }

    /**
     * @param list<RerankCandidate> $candidates
     */
    private function runTest(string $query, array $candidates, RerankOptions $options): RerankResult
    {
        if (null !== $this->http->resolveModel($options->modelKey)) {
            return $this->http->rerank($query, $candidates, count($candidates), $options);
        }
        if ($this->plugConfig->isRerankLlmFallback()) {
            return $this->llm->rerank($query, $candidates, count($candidates), $options);
        }

        throw new \RuntimeException('No rerank model is bound');
    }

    /**
     * @return list<array{key: string, label: string, health: array{available: bool, reason: ?string}}>
     */
    private function adapters(): array
    {
        $out = [];
        foreach ($this->registry->all() as $adapter) {
            $health = $adapter->health();
            $out[] = [
                'key' => $adapter->key(),
                'label' => $adapter->descriptor()->label,
                'health' => [
                    'available' => $health->available,
                    'reason' => $health->reason,
                ],
            ];
        }

        return $out;
    }

    /**
     * @return list<array{key: string, label: string, available: bool, reason: ?string}>
     */
    private function models(): array
    {
        $seen = [];
        $out = [];
        foreach ($this->models->findByTag('rerank', false) as $model) {
            $row = $this->modelRow($model);
            $seen[$row['key']] = true;
            $out[] = $row;
        }

        foreach (ModelCatalog::all() as $catalog) {
            if ('rerank' !== ($catalog['tag'] ?? '')) {
                continue;
            }
            $key = RerankCatalog::key((string) $catalog['service'], (string) $catalog['providerId'], 'rerank');
            if (isset($seen[$key])) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'label' => (string) $catalog['name'],
                'available' => false,
                'reason' => 'Not seeded yet',
            ];
        }

        return $out;
    }

    /**
     * @return array{key: string, label: string, available: bool, reason: ?string}
     */
    private function modelRow(Model $model): array
    {
        $health = $this->http->healthForModel($model);

        return [
            'key' => RerankCatalog::keyForModel($model),
            'label' => $model->getName(),
            'available' => $health->available,
            'reason' => $health->reason,
        ];
    }

    /**
     * @return array<string, array{configured: bool, source: string, origin: ?string, maskedKey: string}>
     */
    private function keyStatuses(): array
    {
        $out = [];
        foreach (['jina', 'cohere', 'voyage'] as $provider) {
            $out[$provider] = $this->keys->getStatus($provider);
        }

        return $out;
    }
}
