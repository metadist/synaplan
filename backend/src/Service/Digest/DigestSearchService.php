<?php

declare(strict_types=1);

namespace App\Service\Digest;

use App\Entity\Message;
use App\Repository\MessageDigestRepository;
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
        private MessageDigestRepository $digestRepository,
        private MessageDigestConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Search + recency re-rank + stage-2 message pull.
     *
     * Hits are confirmed against BMESSAGEDIGESTS (active row, same user)
     * before they can reach a prompt. A Qdrant point whose row was
     * deactivated or deleted is dropped even when its payload still says active.
     *
     * @param float[]   $queryVector       Embedding of the current user prompt (memory embedding model)
     * @param list<int> $excludeMessageIds Message ids already in the prompt verbatim. Digests of
     *                                     those messages are dropped; older messages of the same
     *                                     chat stay searchable
     * @param int|null  $now               Injectable clock for deterministic tests
     *
     * @return list<array{message_id: int, chat_id: int, title: string, channel: string, source_date: int, score: float, effective_score: float, excerpt: string|null}>
     */
    public function search(int $userId, array $queryVector, array $excludeMessageIds = [], ?int $now = null): array
    {
        if ([] === $queryVector) {
            return [];
        }

        $topK = $this->config->getTopK();

        try {
            // Over-fetch so dropping the verbatim window and unconfirmed
            // points cannot short-change the caller.
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

        $exclude = [];
        foreach ($excludeMessageIds as $messageId) {
            $exclude[(int) $messageId] = true;
        }

        /** @var list<array{payload: array<string, mixed>, message_id: int, digest_id: int|null, score: float}> $candidates */
        $candidates = [];
        foreach ($rawHits as $hit) {
            if (!is_array($hit)) {
                continue;
            }
            $payload = is_array($hit['payload'] ?? null) ? $hit['payload'] : [];
            $messageId = (int) ($payload['message_id'] ?? 0);
            $title = trim((string) ($payload['title'] ?? ''));
            if (0 === $messageId || '' === $title) {
                continue;
            }
            $candidates[] = [
                'payload' => $payload,
                'message_id' => $messageId,
                'digest_id' => self::digestIdOfHit($userId, $hit, $payload),
                'score' => (float) ($hit['score'] ?? 0.0),
            ];
        }

        if ([] === $candidates) {
            return [];
        }

        $digestIds = [];
        foreach ($candidates as $candidate) {
            if (null !== $candidate['digest_id']) {
                $digestIds[] = $candidate['digest_id'];
            }
        }

        try {
            $active = $this->digestRepository->findActiveMatches(
                $userId,
                array_column($candidates, 'message_id'),
                $digestIds,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Digest confirmation failed', ['user_id' => $userId, 'error' => $e->getMessage()]);

            return [];
        }

        $activeMessages = array_fill_keys($active['message_ids'], true);
        $activeDigests = array_fill_keys($active['digest_ids'], true);

        $now ??= time();
        $halfLifeSeconds = $this->config->getRecencyHalfLifeDays() * 86400;

        $hits = [];
        foreach ($candidates as $candidate) {
            $messageId = $candidate['message_id'];
            $digestId = $candidate['digest_id'];
            $confirmed = isset($activeMessages[$messageId])
                || (null !== $digestId && isset($activeDigests[$digestId]));
            if (!$confirmed || isset($exclude[$messageId])) {
                continue;
            }

            $payload = $candidate['payload'];
            $sourceDate = (int) ($payload['source_date'] ?? 0);
            $score = $candidate['score'];

            $hits[] = [
                'message_id' => $messageId,
                'chat_id' => (int) ($payload['chat_id'] ?? 0),
                'title' => trim((string) ($payload['title'] ?? '')),
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
     * Digest id carried by the Qdrant payload (`digest_id`, or the logical
     * point id `dig_{user}_{id}` stored as `_point_id` / the hit id).
     *
     * @param array<string, mixed> $hit
     * @param array<string, mixed> $payload
     */
    private static function digestIdOfHit(int $userId, array $hit, array $payload): ?int
    {
        if (isset($payload['digest_id']) && is_numeric($payload['digest_id'])) {
            $id = (int) $payload['digest_id'];
            if ($id > 0) {
                return $id;
            }
        }

        foreach ([$payload['_point_id'] ?? null, $hit['id'] ?? null] as $candidate) {
            if (!is_string($candidate) || '' === $candidate) {
                continue;
            }
            $parsed = MessageDigestService::digestIdFromPointId($userId, $candidate);
            if (null !== $parsed && $parsed > 0) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * Immediate cross-chat recall: the verbatim tail of the user's most
     * recently updated other chat. No embedding or digest index required.
     *
     * @return list<array{message_id: int, chat_id: int, title: string, channel: string, source_date: int, score: float, effective_score: float, excerpt: string|null}>
     */
    public function recentOtherChatTail(int $userId, ?int $excludeChatId): array
    {
        if (null === $excludeChatId || $excludeChatId <= 0) {
            return [];
        }

        $messages = $this->messageRepository->findRecentOtherChatTail($userId, $excludeChatId);
        $out = [];
        foreach ($messages as $msg) {
            $id = $msg->getId();
            if (null === $id) {
                continue;
            }

            $text = $this->messageBody($msg);
            $title = '' !== $text
                ? (mb_strlen($text) > 120 ? mb_substr($text, 0, 117).'…' : $text)
                : '(empty)';
            $excerpt = $text;
            if (mb_strlen($excerpt) > self::EXCERPT_MAX_CHARS) {
                $excerpt = mb_substr($excerpt, 0, self::EXCERPT_MAX_CHARS).'…';
            }

            $out[] = [
                'message_id' => $id,
                'chat_id' => (int) $msg->getChatId(),
                'title' => $title,
                'channel' => (string) $msg->getProviderIndex(),
                'source_date' => $msg->getUnixTimestamp(),
                'score' => 1.0,
                'effective_score' => 1.0,
                'excerpt' => '' !== $excerpt ? $excerpt : null,
            ];
        }

        return $out;
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

            $combined = $this->messageBody($message);

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

    /**
     * Chat text plus extracted file text — file-only messages have an empty
     * `text` but still carry a digestable body in `fileText`.
     */
    private function messageBody(Message $message): string
    {
        $text = trim($message->getText());
        $fileText = trim($message->getFileText());
        if ('' === $fileText) {
            return $text;
        }

        return '' !== $text ? $text."\n".$fileText : $fileText;
    }
}
