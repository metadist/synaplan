<?php

declare(strict_types=1);

namespace App\Service\VectorSearch;

use Psr\Log\LoggerInterface;

/**
 * Full-text payload index and keyword ranking for document chunks.
 *
 * Qdrant scroll does not rank by relevance, so a match is ordered here:
 * more of the query's words first, then an exact phrase.
 */
final class QdrantTextSearch
{
    public const FIELD = 'text';

    /** @var array{type: string, tokenizer: string, min_token_len: int, max_token_len: int, lowercase: bool} */
    public const SCHEMA = [
        'type' => 'text',
        'tokenizer' => 'word',
        'min_token_len' => 2,
        'max_token_len' => 40,
        'lowercase' => true,
    ];

    /**
     * @param array<string, mixed> $filter
     *
     * @return array<string, mixed>
     */
    public static function withText(array $filter, string $query): array
    {
        $text = ['key' => self::FIELD, 'match' => ['text' => $query]];
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
     * @param callable(): list<array<string, mixed>> $scroll
     *
     * @return list<array{id: string, score: float, payload: array<string, mixed>}>
     */
    public static function collect(string $text, int $limit, callable $scroll, LoggerInterface $logger, int $userId): array
    {
        $text = trim($text);
        if ('' === $text || $limit < 1) {
            return [];
        }

        try {
            return self::rank($scroll(), $text, $limit);
        } catch (\Throwable $e) {
            $logger->error('Failed to search documents by keyword', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param list<array<string, mixed>> $points
     *
     * @return list<array{id: string, score: float, payload: array<string, mixed>}>
     */
    public static function rank(array $points, string $query, int $limit): array
    {
        $tokens = self::tokens($query);
        $ranked = [];
        foreach ($points as $point) {
            $payload = is_array($point['payload'] ?? null) ? $point['payload'] : [];
            $body = mb_strtolower((string) ($payload['text'] ?? ''));
            $hits = 0;
            foreach ($tokens as $token) {
                if (str_contains($body, $token)) {
                    ++$hits;
                }
            }
            $id = (string) ($payload['_point_id'] ?? $point['id'] ?? '');
            if ('' === $id) {
                continue;
            }
            $ranked[] = ['id' => $id, 'score' => (float) $hits, 'payload' => $payload, 'text' => $body];
        }

        $phrase = mb_strtolower($query);
        usort($ranked, static function (array $a, array $b) use ($phrase): int {
            $byScore = $b['score'] <=> $a['score'];
            if (0 !== $byScore) {
                return $byScore;
            }

            return str_contains($b['text'], $phrase) <=> str_contains($a['text'], $phrase);
        });

        $out = [];
        foreach (array_slice($ranked, 0, max(0, $limit)) as $row) {
            $out[] = ['id' => $row['id'], 'score' => $row['score'], 'payload' => $row['payload']];
        }

        return $out;
    }

    /** @return list<string> */
    private static function tokens(string $query): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            if (strlen($part) >= 2) {
                $tokens[$part] = $part;
            }
        }

        return array_values($tokens);
    }
}
