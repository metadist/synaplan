<?php

declare(strict_types=1);

namespace App\Service\VectorSearch;

/**
 * Full-text payload index and keyword ranking for document chunks.
 *
 * Each query word is weighted by how rare it is inside the caller's scope
 * (BM25 idf from exact Qdrant counts), so "Art. 17" outranks "what does it
 * say"; a word in more than half the chunks is dropped while a rarer one is
 * left. The rarest words pick the candidates; Qdrant scores them by the summed
 * weight of the words a chunk contains. Scores are 0..1: the share of the
 * query's weight a chunk matched.
 */
final class QdrantTextSearch
{
    public const FIELD = 'text';

    /** @var array{type: string, tokenizer: string, min_token_len: int, max_token_len: int, lowercase: bool} */
    public const SCHEMA = [
        'type' => 'text',
        'tokenizer' => 'word',
        'min_token_len' => self::MIN_TOKEN_LEN,
        'max_token_len' => self::MAX_TOKEN_LEN,
        'lowercase' => true,
    ];

    private const MIN_TOKEN_LEN = 2;
    private const MAX_TOKEN_LEN = 40;
    /** Bounds the count requests one query makes. */
    private const MAX_TOKENS = 16;
    /** Chunks Qdrant scores per query. */
    private const CANDIDATES = 512;

    /**
     * @param array<string, mixed>                                         $scope the owner filter the vector leg uses
     * @param callable(string, array<string, mixed>): array<string, mixed> $post  one POST to `points/{endpoint}`
     *
     * @return list<array{id: string, score: float, payload: array<string, mixed>}>
     */
    public static function search(string $query, array $scope, int $limit, callable $post): array
    {
        $tokens = self::tokens($query);
        if ([] === $tokens || $limit < 1) {
            return [];
        }
        $total = self::count($post, $scope);
        if ($total < 1) {
            return [];
        }

        $frequency = [];
        foreach ($tokens as $token) {
            $found = self::count($post, self::withText($scope, [$token]));
            if ($found > 0) {
                $frequency[$token] = $found;
            }
        }
        $distinctive = array_filter($frequency, static fn (int $found): bool => $found <= $total / 2);
        if ([] !== $distinctive) {
            $frequency = $distinctive;
        }
        if ([] === $frequency) {
            return [];
        }
        asort($frequency);

        $weights = [];
        foreach ($frequency as $token => $found) {
            $weights[$token] = log(1 + ($total - $found + 0.5) / ($found + 0.5));
        }
        $selective = array_keys(array_filter($frequency, static fn (int $found): bool => $found <= self::CANDIDATES));
        if ([] === $selective) {
            $selective = [array_key_first($frequency)];
        }

        $body = $post('query', [
            'prefetch' => ['filter' => self::withText($scope, $selective), 'limit' => self::CANDIDATES],
            'query' => ['formula' => ['sum' => array_map(
                static fn (string $token, float $weight): array => ['mult' => [round($weight, 4), self::condition($token)]],
                array_keys($weights),
                array_values($weights),
            )]],
            'limit' => $limit,
            'with_payload' => true,
        ]);
        $points = is_array($body['result']['points'] ?? null) ? $body['result']['points'] : [];

        return self::rows($points, array_sum($weights));
    }

    /**
     * The scope filter, narrowed to chunks that contain any of the words.
     * A multi-owner scope gets the words inside each owner branch.
     *
     * @param array<string, mixed> $filter
     * @param list<string>         $tokens
     *
     * @return array<string, mixed>
     */
    public static function withText(array $filter, array $tokens): array
    {
        $text = ['should' => array_map(self::condition(...), $tokens)];
        if (isset($filter['should']) && is_array($filter['should'])) {
            foreach ($filter['should'] as $i => $branch) {
                if (!is_array($branch)) {
                    continue;
                }
                $must = is_array($branch['must'] ?? null) ? $branch['must'] : [];
                $must[] = $text;
                $branch['must'] = $must;
                $filter['should'][$i] = $branch;
            }

            return $filter;
        }

        $must = is_array($filter['must'] ?? null) ? $filter['must'] : [];
        $must[] = $text;
        $filter['must'] = $must;

        return $filter;
    }

    /**
     * Words as the index stores them: lowercase, split on anything that is
     * not a letter or digit, inside the index token length.
     *
     * @return list<string>
     */
    public static function tokens(string $query): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            $length = mb_strlen($part);
            if ($length >= self::MIN_TOKEN_LEN && $length <= self::MAX_TOKEN_LEN) {
                $tokens[$part] = $part;
            }
        }

        return array_slice(array_values($tokens), 0, self::MAX_TOKENS);
    }

    /** @return array{key: string, match: array{text: string}} */
    private static function condition(string $token): array
    {
        return ['key' => self::FIELD, 'match' => ['text' => $token]];
    }

    /**
     * @param callable(string, array<string, mixed>): array<string, mixed> $post
     * @param array<string, mixed>                                         $filter
     */
    private static function count(callable $post, array $filter): int
    {
        $body = $post('count', ['filter' => $filter, 'exact' => true]);

        return (int) ($body['result']['count'] ?? 0);
    }

    /**
     * @param array<mixed> $points
     *
     * @return list<array{id: string, score: float, payload: array<string, mixed>}>
     */
    private static function rows(array $points, float $weight): array
    {
        $out = [];
        foreach ($points as $point) {
            if (!is_array($point)) {
                continue;
            }
            $payload = is_array($point['payload'] ?? null) ? $point['payload'] : [];
            $id = (string) ($payload['_point_id'] ?? $point['id'] ?? '');
            if ('' === $id) {
                continue;
            }
            $score = $weight > 0 ? (float) ($point['score'] ?? 0) / $weight : 0.0;
            $out[] = ['id' => $id, 'score' => round(min(1.0, $score), 4), 'payload' => $payload];
        }

        return $out;
    }
}
