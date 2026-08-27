<?php

declare(strict_types=1);

namespace App\Service\Digest;

use App\Repository\MessageRepository;
use App\Service\VectorSearch\QdrantClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Per-turn retrieval over the message digest index (deep memory).
 *
 * Given the already-computed query embedding of the user's prompt, finds the
 * most relevant digest lines, re-ranks them with a slow recency decay, and
 * pulls the full source text for the top hits — so a prompt about the office
 * rent finds the actual letter from three months ago, not just a hint that
 * it exists.
 */
final readonly class DigestSearchService
{
    /** Per-message excerpt cap for stage-2 pulls (chars). */
    private const EXCERPT_MAX_CHARS = 1500;

    public function __construct(
        private QdrantClientInterface $qdrantClient,
        private MessageRepository $messageRepository,
        private MessageDigestConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Search + recency re-rank + stage-2 message pull.
     *
     * @param float[]  $queryVector   Embedding of the current user prompt (memory embedding model)
     * @param int|null $excludeChatId Digests from this chat are dropped — its recent
     *                                messages are already in the context verbatim
     * @param int|null $now           Injectable clock for deterministic tests
     *
     * @return list<array{message_id: int, chat_id: int, title: string, channel: string, source_date: int, score: float, effective_score: float, excerpt: string|null}>
     */
    public function search(int $userId, array $queryVector, ?int $excludeChatId = null, ?int $now = null): array
    {
        if ([] === $queryVector) {
            return [];
        }

        $topK = $this->config->getTopK();

        try {
            // Over-fetch so the current-chat exclusion below cannot
            // short-change the caller.
            $rawHits = $this->qdrantClient->searchDigests(
                $queryVector,
                $userId,
                limit: $topK * 2,
                minScore: $this->config->getMinScore(),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Digest search failed', ['user_id' => $userId, 'error' => $e->getMessage()]);

            return [];
        }

        $now ??= time();
        $halfLifeSeconds = $this->config->getRecencyHalfLifeDays() * 86400;

        $hits = [];
        foreach ($rawHits as $hit) {
            $payload = $hit['payload'] ?? [];
            $messageId = (int) ($payload['message_id'] ?? 0);
            $chatId = (int) ($payload['chat_id'] ?? 0);
            $title = trim((string) ($payload['title'] ?? ''));

            if (0 === $messageId || '' === $title) {
                continue;
            }
            if (null !== $excludeChatId && $chatId === $excludeChatId) {
                continue;
            }

            $sourceDate = (int) ($payload['source_date'] ?? 0);
            $score = (float) ($hit['score'] ?? 0.0);

            $hits[] = [
                'message_id' => $messageId,
                'chat_id' => $chatId,
                'title' => $title,
                'channel' => (string) ($payload['channel'] ?? ''),
                'source_date' => $sourceDate,
                'score' => $score,
                'effective_score' => self::effectiveScore($score, max(0, $now - $sourceDate), $halfLifeSeconds),
                'excerpt' => null,
            ];
        }

        usort($hits, static fn (array $a, array $b): int => $b['effective_score'] <=> $a['effective_score']);
        $hits = array_slice($hits, 0, $topK);

        return $this->pullTopMessages($userId, $hits);
    }

    /**
     * The recency re-rank formula, shared with `app:digest:eval` so the eval
     * tunes exactly what production runs: slow exponential decay
     * `effective = score * 0.5^(age / half-life)`. Age must already be
     * clamped at >= 0 so clock skew can never boost a hit.
     */
    public static function effectiveScore(float $score, int $ageSeconds, int $halfLifeSeconds): float
    {
        if ($halfLifeSeconds <= 0) {
            return $score;
        }

        return $score * 0.5 ** ($ageSeconds / $halfLifeSeconds);
    }

    /**
     * Stage 2: attach a clipped verbatim excerpt of the source message to the
     * best hits, so the model can quote the actual content rather than only
     * knowing it exists.
     *
     * @param list<array{message_id: int, chat_id: int, title: string, channel: string, source_date: int, score: float, effective_score: float, excerpt: string|null}> $hits
     *
     * @return list<array{message_id: int, chat_id: int, title: string, channel: string, source_date: int, score: float, effective_score: float, excerpt: string|null}>
     */
    private function pullTopMessages(int $userId, array $hits): array
    {
        $pullBudget = $this->config->getPullTopN();
        $pullMinScore = $this->config->getPullMinScore();

        foreach ($hits as $i => $hit) {
            if ($pullBudget <= 0) {
                break;
            }
            if ($hit['score'] < $pullMinScore) {
                continue;
            }

            $message = $this->messageRepository->find($hit['message_id']);
            if (null === $message || $message->getUserId() !== $userId) {
                continue;
            }

            $text = trim($message->getText());
            $fileText = trim($message->getFileText());
            $combined = $text;
            if ('' !== $fileText) {
                $combined .= ('' !== $combined ? "\n" : '').$fileText;
            }

            if ('' === $combined) {
                continue;
            }

            if (mb_strlen($combined) > self::EXCERPT_MAX_CHARS) {
                $combined = mb_substr($combined, 0, self::EXCERPT_MAX_CHARS).'…';
            }

            $hits[$i]['excerpt'] = $combined;
            --$pullBudget;
        }

        return $hits;
    }
}
