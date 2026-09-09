<?php

declare(strict_types=1);

namespace App\Plug\Rerank\Adapter;

use App\AI\Credential\OpenAiCompatibleEndpointRegistry;
use App\Entity\Model;
use App\Plug\PlugDescriptor;
use App\Plug\PlugHealth;
use App\Plug\PlugKeyStore;
use App\Plug\Rerank\Client\HttpRerankClient;
use App\Plug\Rerank\RerankCatalog;
use App\Plug\Rerank\RerankOptions;
use App\Plug\Rerank\RerankProviderInterface;
use App\Plug\Rerank\RerankResult;
use App\Repository\ModelRepository;
use App\Service\ModelConfigService;

/**
 * One adapter, four wire shapes chosen by the bound model's BSERVICE.
 *
 * @internal
 */
final readonly class HttpRerankAdapter implements RerankProviderInterface
{
    public const KEY = 'http';

    public function __construct(
        private HttpRerankClient $client,
        private ModelConfigService $modelConfig,
        private ModelRepository $models,
        private OpenAiCompatibleEndpointRegistry $endpoints,
        private PlugKeyStore $keys,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function descriptor(): PlugDescriptor
    {
        return new PlugDescriptor(
            self::KEY,
            'HTTP rerank (TEI, Jina, Cohere, Voyage)',
            'https://docs.synaplan.com/rag',
            ['PLUGS.RERANK.ENABLED'],
            'mixed',
        );
    }

    public function rerank(string $query, array $candidates, int $topK, RerankOptions $options): RerankResult
    {
        $started = hrtime(true);
        $model = $this->resolveModel($options->modelKey);
        if (null === $model) {
            throw new \RuntimeException('No rerank model is bound');
        }

        $texts = [];
        foreach ($candidates as $candidate) {
            $texts[] = $this->truncate($candidate->text, $options->maxCandidateChars);
        }

        $ranked = $this->request($model, $query, $texts, $topK, $options->latencyBudgetMs);
        $ms = (int) ((hrtime(true) - $started) / 1_000_000);
        $hits = [];
        foreach ($ranked as $row) {
            $index = $row['index'];
            if (!isset($candidates[$index])) {
                continue;
            }
            $candidate = $candidates[$index];
            $hits[] = [
                'id' => $candidate->id,
                'text' => $candidate->text,
                'score' => $row['score'],
            ];
            if (count($hits) >= $topK) {
                break;
            }
        }

        return new RerankResult($hits, self::KEY.':'.strtolower($model->getService()), $ms);
    }

    public function health(): PlugHealth
    {
        $model = $this->resolveModel(null);
        if (null === $model) {
            return PlugHealth::unavailable('No rerank model is bound');
        }

        return $this->healthForModel($model);
    }

    public function healthForModel(Model $model): PlugHealth
    {
        $service = strtolower($model->getService());
        if (OpenAiCompatibleEndpointRegistry::PROVIDER_NAME === $service) {
            $endpoint = $this->endpoints->resolveForModel($model->getProviderId());
            if (null === $endpoint) {
                return PlugHealth::unavailable('No OpenAI-compatible endpoint is registered for this reranker');
            }
            if (!$this->advertisesRerank($endpoint)) {
                return PlugHealth::unavailable(sprintf('Endpoint "%s" does not advertise the rerank capability', $endpoint['name']));
            }

            return PlugHealth::available();
        }

        if (\in_array($service, ['jina', 'cohere', 'voyage'], true)) {
            $key = $this->keys->getKey($service);

            return null !== $key && '' !== $key
                ? PlugHealth::available()
                : PlugHealth::unavailable(ucfirst($service).' API key is not configured');
        }

        return PlugHealth::unavailable('Unsupported rerank service: '.$model->getService());
    }

    public function resolveModel(?string $modelKey): ?Model
    {
        if (null !== $modelKey && '' !== trim($modelKey)) {
            $bid = RerankCatalog::bidByKey($modelKey);
            if (null !== $bid) {
                return $this->models->find($bid);
            }

            foreach ($this->models->findByTag('rerank', false) as $model) {
                if (RerankCatalog::keyForModel($model) === strtolower(trim($modelKey))) {
                    return $model;
                }
            }

            return null;
        }

        $id = $this->modelConfig->getDefaultModel('RERANK');

        return null !== $id ? $this->models->find($id) : null;
    }

    /**
     * @param list<string> $texts
     *
     * @return list<array{index: int, score: float}>
     */
    private function request(Model $model, string $query, array $texts, int $topK, int $budgetMs): array
    {
        $service = strtolower($model->getService());
        $timeout = max(0.1, $budgetMs / 1000);
        $providerId = $model->getProviderId();

        if (OpenAiCompatibleEndpointRegistry::PROVIDER_NAME === $service) {
            $endpoint = $this->endpoints->resolveForModel($providerId);
            if (null === $endpoint) {
                throw new \RuntimeException('No OpenAI-compatible endpoint is registered for this reranker');
            }
            if (!$this->advertisesRerank($endpoint)) {
                throw new \RuntimeException(sprintf('Endpoint "%s" does not advertise the rerank capability', $endpoint['name']));
            }
            $url = rtrim($endpoint['base_url'], '/').'/rerank';
            $headers = $endpoint['headers'];
            if ('' !== $endpoint['api_key']) {
                $headers['Authorization'] = 'Bearer '.$endpoint['api_key'];
            }
            $payload = $this->client->postJson($url, $headers, [
                'query' => $query,
                'texts' => $texts,
                'raw_scores' => false,
            ], $timeout);

            return $this->mapIndexed($payload, 'score');
        }

        $key = $this->keys->getKey($service);
        if (null === $key || '' === $key) {
            throw new \RuntimeException(ucfirst($service).' API key is not configured');
        }

        return match ($service) {
            'jina' => $this->mapResults($this->client->postJson(
                'https://api.jina.ai/v1/rerank',
                ['Authorization' => 'Bearer '.$key],
                ['model' => $providerId, 'query' => $query, 'documents' => $texts, 'top_n' => $topK],
                $timeout,
            ), 'relevance_score'),
            'cohere' => $this->mapResults($this->client->postJson(
                'https://api.cohere.com/v2/rerank',
                ['Authorization' => 'Bearer '.$key],
                ['model' => $providerId, 'query' => $query, 'documents' => $texts, 'top_n' => $topK],
                $timeout,
            ), 'relevance_score'),
            'voyage' => $this->mapVoyage($this->client->postJson(
                'https://api.voyageai.com/v1/rerank',
                ['Authorization' => 'Bearer '.$key],
                ['model' => $providerId, 'query' => $query, 'documents' => $texts, 'top_k' => $topK],
                $timeout,
            )),
            default => throw new \RuntimeException('Unsupported rerank service: '.$model->getService()),
        };
    }

    /**
     * TEI returns a top-level list `[{index, score}]`.
     *
     * @param array<int|string, mixed> $payload
     *
     * @return list<array{index: int, score: float}>
     */
    private function mapIndexed(array $payload, string $scoreKey): array
    {
        $rows = $payload;
        if (false === array_is_list($payload)) {
            $inner = $payload['results'] ?? $payload['data'] ?? null;
            $rows = \is_array($inner) ? $inner : [];
        }

        return $this->mapScoreRows($rows, $scoreKey);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array{index: int, score: float}>
     */
    private function mapResults(array $payload, string $scoreKey): array
    {
        $rows = $payload['results'] ?? [];

        return $this->mapScoreRows(\is_array($rows) ? $rows : [], $scoreKey);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array{index: int, score: float}>
     */
    private function mapVoyage(array $payload): array
    {
        $rows = $payload['data'] ?? [];

        return $this->mapScoreRows(\is_array($rows) ? $rows : [], 'relevance_score');
    }

    /**
     * @param list<mixed>|array<string, mixed> $rows
     *
     * @return list<array{index: int, score: float}>
     */
    private function mapScoreRows(array $rows, string $scoreKey): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!\is_array($row) || !is_numeric($row['index'] ?? null)) {
                continue;
            }
            $score = $row[$scoreKey] ?? $row['score'] ?? null;
            if (!is_numeric($score)) {
                continue;
            }
            $out[] = ['index' => (int) $row['index'], 'score' => (float) $score];
        }
        usort($out, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $out;
    }

    /**
     * A chat-only gateway has no /rerank route; binding it would fail on every
     * search while health looked green.
     *
     * @param array{capabilities: string[]} $endpoint
     */
    private function advertisesRerank(array $endpoint): bool
    {
        return \in_array('rerank', $endpoint['capabilities'], true);
    }

    private function truncate(string $text, int $maxChars): string
    {
        if ($maxChars <= 0 || mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return mb_substr($text, 0, $maxChars);
    }
}
