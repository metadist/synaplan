<?php

namespace App\AI\Interface;

interface EmbeddingProviderInterface extends ProviderMetadataInterface
{
    /**
     * Generate vector embedding for text.
     *
     * @param string $text    Text to embed
     * @param array  $options options: model (required), etc
     *
     * @return array Embedding vector
     */
    public function embed(string $text, array $options = []): array;

    /**
     * Batch embedding for multiple texts.
     *
     * @param array $texts   Texts to embed
     * @param array $options options: model (required), etc
     *
     * @return array Array of embedding vectors
     */
    public function embedBatch(array $texts, array $options = []): array;

    /**
     * Get embedding dimensions for a model.
     *
     * @param string $model Model name (e.g. 'text-embedding-3-small')
     *
     * @return int Dimension count (e.g. 1536)
     */
    public function getDimensions(string $model): int;
}
