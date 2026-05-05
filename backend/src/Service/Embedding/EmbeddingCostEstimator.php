<?php

declare(strict_types=1);

namespace App\Service\Embedding;

use App\Service\ModelConfigService;
use App\Service\VectorSearch\QdrantClientInterface;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * EmbeddingCostEstimator — pre-flight estimator for the cost of switching
 * the active VECTORIZE model.
 *
 * Returns:
 *   - per-scope chunk and estimated token counts
 *   - estimated USD cost (from the static pricing table, overridable via
 *     BCONFIG group `EMBEDDING_PRICING`)
 *   - severity (`info` / `warning` / `critical`) so the UI can pick the
 *     right colour and confirmation flow without re-deriving the rules.
 *
 * Token counts are heuristic: 1 chunk ≈ ~500 tokens, 1 char ≈ 1/4 token.
 * That is good enough for UI warnings — once a switch is queued, the
 * ReVectorizeJob updates `tokens_processed` with the real provider-
 * reported counts, which the run-history table surfaces afterwards.
 */
final class EmbeddingCostEstimator
{
    /**
     * Severity thresholds in tokens. Tunable per environment via
     * BCONFIG[group=EMBEDDING_PRICING, setting=THRESHOLD_*].
     */
    public const THRESHOLD_WARNING = 1_000_000;       // 1M tokens
    public const THRESHOLD_CRITICAL = 10_000_000;     // 10M tokens

    /**
     * Heuristic: 1 stored chunk averages ~500 tokens after our default
     * chunker. Keeps the estimator cheap (no payload scan) and is good
     * enough for warning bands; ReVectorizeJob records the real count.
     */
    public const TOKENS_PER_CHUNK_HEURISTIC = 500;

    /**
     * Tokens per Synapse topic — they are short (description + keywords),
     * usually well under 200 tokens. We round up to 200 to keep the
     * estimate conservative.
     */
    public const TOKENS_PER_TOPIC_HEURISTIC = 200;

    /**
     * Default pricing in USD per 1M tokens. Overridable per environment
     * via BCONFIG[group=EMBEDDING_PRICING, setting=PROVIDER:MODEL].
     *
     * Numbers reflect publicly listed embedding API prices at the time
     * of writing. Self-hosted providers (ollama) are free.
     *
     * @var array<string, float>
     */
    private const DEFAULT_PRICING_PER_M_TOKENS = [
        'cloudflare:bge-m3' => 0.012,
        'cloudflare:*' => 0.012,
        'openai:text-embedding-3-small' => 0.020,
        'openai:text-embedding-3-large' => 0.130,
        'openai:text-embedding-ada-002' => 0.100,
        'openai:*' => 0.020,
        'gemini:text-embedding-004' => 0.025,
        'gemini:*' => 0.025,
        'google:text-embedding-004' => 0.025,
        'google:*' => 0.025,
        'groq:*' => 0.020,
        'ollama:*' => 0.0,
        'test:*' => 0.0,
    ];

    public function __construct(
        private readonly QdrantClientInterface $qdrantClient,
        private readonly ModelConfigService $modelConfigService,
        private readonly Connection $connection,
        private readonly EmbeddingMetadataService $embeddingMetadata,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Build the full cost-estimate payload for a model switch.
     *
     * @return array{
     *     fromModelId: ?int,
     *     toModelId: int,
     *     fromModel: array{provider: ?string, model: ?string, modelId: ?int, vectorDim: int},
     *     toModel: array{provider: ?string, model: ?string, modelId: int, vectorDim: int, pricePerMTokens: float},
     *     scopes: array<string, array{chunks: int, tokensEstimated: int, costEstimatedUsd: float}>,
     *     totals: array{chunks: int, tokensEstimated: int, costEstimatedUsd: float},
     *     severity: string,
     *     thresholds: array{warning: int, critical: int}
     * }
     */
    public function estimateChange(int $toModelId, ?int $fromModelId = null): array
    {
        $fromModelId = $fromModelId ?? $this->embeddingMetadata->getCurrentModelId();
        $fromModel = $this->buildModelInfo($fromModelId);
        $toModel = $this->buildModelInfo($toModelId);
        $toModel['pricePerMTokens'] = $this->resolvePricePerMTokens(
            $toModel['provider'] ?? '',
            $toModel['model'] ?? '',
        );

        $scopes = [
            'documents' => $this->estimateDocumentsScope($toModel['pricePerMTokens']),
            'memories' => $this->estimateMemoriesScope($toModel['pricePerMTokens']),
            'synapse' => $this->estimateSynapseScope($toModel['pricePerMTokens']),
        ];

        $totalChunks = 0;
        $totalTokens = 0;
        $totalCost = 0.0;
        foreach ($scopes as $scope) {
            $totalChunks += $scope['chunks'];
            $totalTokens += $scope['tokensEstimated'];
            $totalCost += $scope['costEstimatedUsd'];
        }

        return [
            'fromModelId' => $fromModelId,
            'toModelId' => $toModelId,
            'fromModel' => $fromModel,
            'toModel' => $toModel,
            'scopes' => $scopes,
            'totals' => [
                'chunks' => $totalChunks,
                'tokensEstimated' => $totalTokens,
                'costEstimatedUsd' => round($totalCost, 4),
            ],
            'severity' => $this->classifySeverity($totalTokens),
            'thresholds' => [
                'warning' => self::THRESHOLD_WARNING,
                'critical' => self::THRESHOLD_CRITICAL,
            ],
        ];
    }

    /**
     * Lightweight overload that returns just the severity, used by the
     * change-guard before a switch is even attempted.
     */
    public function classifySeverity(int $tokensEstimated): string
    {
        if ($tokensEstimated >= self::THRESHOLD_CRITICAL) {
            return 'critical';
        }
        if ($tokensEstimated >= self::THRESHOLD_WARNING) {
            return 'warning';
        }

        return 'info';
    }

    /**
     * @return array{chunks: int, tokensEstimated: int, costEstimatedUsd: float}
     */
    private function estimateDocumentsScope(float $pricePerMTokens): array
    {
        // Use BRAG (the durable copy) over Qdrant counts: BRAG is the
        // source of truth for re-vectorize, Qdrant is rebuildable.
        try {
            $chunks = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM BRAG');
            $textLen = (int) $this->connection->fetchOne('SELECT COALESCE(SUM(LENGTH(BTEXT)), 0) FROM BRAG');
        } catch (\Throwable $e) {
            $this->logger->warning('CostEstimator: documents scope fallback to Qdrant', [
                'error' => $e->getMessage(),
            ]);
            $chunks = 0;
            $textLen = 0;
        }

        // Prefer real text length (4 chars/token) over chunk-count *500
        // when BRAG is queryable — it is a tighter estimate and the SQL
        // is cheap (single SUM on an indexed column).
        $tokens = $textLen > 0 ? (int) ceil($textLen / 4) : $chunks * self::TOKENS_PER_CHUNK_HEURISTIC;

        return [
            'chunks' => $chunks,
            'tokensEstimated' => $tokens,
            'costEstimatedUsd' => $this->calcCost($tokens, $pricePerMTokens),
        ];
    }

    /**
     * @return array{chunks: int, tokensEstimated: int, costEstimatedUsd: float}
     */
    private function estimateMemoriesScope(float $pricePerMTokens): array
    {
        // Memories live in Qdrant only — count the points and apply the
        // per-chunk heuristic. Scrolling every payload to measure exact
        // text length would be O(n) HTTP round-trips, which we avoid on
        // a UI-blocking pre-flight call.
        try {
            $points = $this->qdrantClient->scrollMemories(0, null, 10000);
        } catch (\Throwable $e) {
            $this->logger->warning('CostEstimator: memories scope unreachable', [
                'error' => $e->getMessage(),
            ]);
            $points = [];
        }

        $chunks = count($points);
        $tokens = $chunks * self::TOKENS_PER_CHUNK_HEURISTIC;

        return [
            'chunks' => $chunks,
            'tokensEstimated' => $tokens,
            'costEstimatedUsd' => $this->calcCost($tokens, $pricePerMTokens),
        ];
    }

    /**
     * @return array{chunks: int, tokensEstimated: int, costEstimatedUsd: float}
     */
    private function estimateSynapseScope(float $pricePerMTokens): array
    {
        $info = $this->qdrantClient->getSynapseCollectionInfo();
        $chunks = (int) ($info['points_count'] ?? 0);
        $tokens = $chunks * self::TOKENS_PER_TOPIC_HEURISTIC;

        return [
            'chunks' => $chunks,
            'tokensEstimated' => $tokens,
            'costEstimatedUsd' => $this->calcCost($tokens, $pricePerMTokens),
        ];
    }

    private function calcCost(int $tokens, float $pricePerMTokens): float
    {
        if ($tokens <= 0 || $pricePerMTokens <= 0.0) {
            return 0.0;
        }

        return round(($tokens / 1_000_000) * $pricePerMTokens, 4);
    }

    /**
     * Pricing lookup precedence:
     *   1. exact match `provider:model` from BCONFIG override
     *   2. exact match from DEFAULT_PRICING_PER_M_TOKENS
     *   3. wildcard match `provider:*` from DEFAULT_PRICING_PER_M_TOKENS
     *   4. 0.0 (unknown / self-hosted)
     */
    private function resolvePricePerMTokens(string $provider, string $model): float
    {
        $provider = strtolower(trim($provider));
        $model = strtolower(trim($model));
        if ('' === $provider) {
            return 0.0;
        }

        $exactKey = sprintf('%s:%s', $provider, $model);

        try {
            $override = $this->connection->fetchOne(
                'SELECT BVALUE FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = :grp AND BSETTING = :key LIMIT 1',
                ['grp' => 'EMBEDDING_PRICING', 'key' => $exactKey],
            );
            if (false !== $override && '' !== (string) $override) {
                return (float) $override;
            }
        } catch (\Throwable) {
            // BCONFIG may not exist in some test fixtures — fall through to defaults
        }

        if (isset(self::DEFAULT_PRICING_PER_M_TOKENS[$exactKey])) {
            return self::DEFAULT_PRICING_PER_M_TOKENS[$exactKey];
        }

        $wildcardKey = sprintf('%s:*', $provider);
        if (isset(self::DEFAULT_PRICING_PER_M_TOKENS[$wildcardKey])) {
            return self::DEFAULT_PRICING_PER_M_TOKENS[$wildcardKey];
        }

        return 0.0;
    }

    /**
     * @return array{provider: ?string, model: ?string, modelId: ?int, vectorDim: int}
     */
    private function buildModelInfo(?int $modelId): array
    {
        if (null === $modelId) {
            return [
                'provider' => null,
                'model' => null,
                'modelId' => null,
                'vectorDim' => EmbeddingMetadataService::DEFAULT_VECTOR_DIM,
            ];
        }

        return [
            'provider' => $this->modelConfigService->getProviderForModel($modelId),
            'model' => $this->modelConfigService->getModelName($modelId),
            'modelId' => $modelId,
            'vectorDim' => EmbeddingMetadataService::DEFAULT_VECTOR_DIM,
        ];
    }
}
