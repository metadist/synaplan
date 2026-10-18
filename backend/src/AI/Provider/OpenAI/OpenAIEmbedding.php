<?php

namespace App\AI\Provider\OpenAI;

use App\AI\Exception\ProviderException;

/**
 * OpenAI embeddings for OpenAIProvider: one text or a batch, the native
 * output width per model, and the `dimensions` truncation only where the
 * caller asks for it on a text-embedding-3 model.
 */
final class OpenAIEmbedding
{
    public function __construct(
        private readonly OpenAIApiClient $api,
    ) {
    }

    public function embed(string $text, array $options = []): array
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Embedding model must be specified in options', 'openai');
        }

        if (null === $this->api->sdk()) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            $params = $this->buildEmbeddingParams($options['model'], $text, $options);

            $response = $this->api->sdk()->embeddings()->create($params);
            $usage = $response->usage;

            return [
                'embedding' => $response['data'][0]['embedding'] ?? [],
                'usage' => [
                    'prompt_tokens' => null !== $usage ? $usage->promptTokens : 0,
                    'total_tokens' => null !== $usage ? $usage->totalTokens : 0,
                ],
            ];
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI embedding error: '.$e->getMessage(), 'openai');
        }
    }

    public function embedBatch(array $texts, array $options = []): array
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Embedding model must be specified in options', 'openai');
        }

        if (null === $this->api->sdk()) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            $params = $this->buildEmbeddingParams($options['model'], $texts, $options);

            $response = $this->api->sdk()->embeddings()->create($params);
            $usage = $response->usage;

            return [
                'embeddings' => array_map(fn ($item) => $item['embedding'], $response['data']),
                'usage' => [
                    'prompt_tokens' => null !== $usage ? $usage->promptTokens : 0,
                    'total_tokens' => null !== $usage ? $usage->totalTokens : 0,
                ],
            ];
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI batch embedding error: '.$e->getMessage(), 'openai');
        }
    }

    public function getDimensions(string $model): int
    {
        // Native output dimensions per the OpenAI embedding catalog. The
        // previous implementation lied here for `text-embedding-3-large`
        // (claimed 1536) because `embed()` used to coerce v3 outputs to
        // 1536 via the API `dimensions` parameter. That coercion is gone
        // (issue #985 — it caused the documents/memories pipeline to
        // recreate Qdrant collections at the WRONG dim because the
        // catalog metadata said 3072 while the actual vectors came back
        // as 1536, resulting in HTTP 400 on every upsert), so this
        // method now reports the true model output.
        return match (true) {
            str_contains($model, 'text-embedding-3-small') => 1536,
            str_contains($model, 'text-embedding-3-large') => 3072,
            str_contains($model, 'text-embedding-ada-002') => 1536,
            default => 1536,
        };
    }

    /**
     * Build the params for an OpenAI embeddings call.
     *
     * Caller may pass `dimensions` in `$options` to opt-in to the v3
     * truncation feature (e.g. shrink `text-embedding-3-large` from
     * 3072 → 1024 to match a fixed collection width). The provider
     * never injects a default truncation on its own — that historical
     * behaviour silently desynchronised catalog `vector_dim` metadata
     * from the actual vector width and corrupted re-vectorize runs.
     * Caller-provided values are forwarded verbatim; an empty/zero
     * value is treated as "no override" so test fixtures stay simple.
     *
     * @param string|list<string>  $input
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function buildEmbeddingParams(string $model, string|array $input, array $options): array
    {
        $params = [
            'model' => $model,
            'input' => $input,
        ];

        $explicitDimensions = $options['dimensions'] ?? null;
        if (is_int($explicitDimensions) && $explicitDimensions > 0
            && str_contains($model, 'text-embedding-3')) {
            $params['dimensions'] = $explicitDimensions;
        }

        return $params;
    }
}
