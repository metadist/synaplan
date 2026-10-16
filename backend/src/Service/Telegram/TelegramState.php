<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Short-lived bot state that has no chat row: updates already handled
 * without storing a message (edits, buttons, album parts) and an open
 * "Not correct" question.
 */
final readonly class TelegramState
{
    private const UPDATE_TTL_SECONDS = 172800;
    private const FEEDBACK_TTL_SECONDS = 900;

    public function __construct(private CacheItemPoolInterface $cache)
    {
    }

    public function wasHandled(string $updateKey): bool
    {
        return $this->cache->getItem($this->updateKey($updateKey))->isHit();
    }

    public function markHandled(string $updateKey): void
    {
        $item = $this->cache->getItem($this->updateKey($updateKey));
        $item->set(true);
        $item->expiresAfter(self::UPDATE_TTL_SECONDS);
        $this->cache->save($item);
    }

    public function askFeedback(int $botRowId, int $answerId, int $promptMessageId): void
    {
        $item = $this->cache->getItem($this->feedbackKey($botRowId));
        $item->set(['answer' => $answerId, 'prompt' => $promptMessageId]);
        $item->expiresAfter(self::FEEDBACK_TTL_SECONDS);
        $this->cache->save($item);
    }

    /**
     * @return array{answer: int, prompt: int}|null
     */
    public function pendingFeedback(int $botRowId): ?array
    {
        $value = $this->cache->getItem($this->feedbackKey($botRowId))->get();
        if (!is_array($value) || !is_int($value['answer'] ?? null) || !is_int($value['prompt'] ?? null)) {
            return null;
        }

        return ['answer' => $value['answer'], 'prompt' => $value['prompt']];
    }

    public function clearFeedback(int $botRowId): void
    {
        $this->cache->deleteItem($this->feedbackKey($botRowId));
    }

    private function updateKey(string $updateKey): string
    {
        return 'telegram_update_'.hash('sha256', $updateKey);
    }

    private function feedbackKey(int $botRowId): string
    {
        return 'telegram_feedback_'.$botRowId;
    }
}
