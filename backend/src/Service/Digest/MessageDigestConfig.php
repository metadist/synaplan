<?php

declare(strict_types=1);

namespace App\Service\Digest;

use App\Repository\ConfigRepository;

/**
 * BCONFIG-backed knobs for the message digest job (group `DIGEST`).
 *
 * All settings have code-side defaults, so no seeder rows are required —
 * operators can override any of them by inserting a BCONFIG row with
 * ownerId 0. The per-user cursor lives in the same group with the user's
 * id as owner.
 */
final class MessageDigestConfig
{
    public const CONFIG_GROUP = 'DIGEST';

    public const KEY_ENABLED = 'ENABLED';
    public const KEY_BATCH_SIZE = 'BATCH_SIZE';
    public const KEY_MAX_BATCHES_PER_USER = 'MAX_BATCHES_PER_USER';
    public const KEY_QUIET_SECONDS = 'QUIET_SECONDS';
    public const KEY_CURSOR = 'CURSOR';
    public const KEY_TOP_K = 'TOP_K';
    public const KEY_MIN_SCORE = 'MIN_SCORE';
    public const KEY_RECENCY_HALF_LIFE_DAYS = 'RECENCY_HALF_LIFE_DAYS';
    public const KEY_PULL_TOP_N = 'PULL_TOP_N';
    public const KEY_PULL_MIN_SCORE = 'PULL_MIN_SCORE';
    public const KEY_BLOCK_MAX_CHARS = 'BLOCK_MAX_CHARS';
    public const KEY_MAX_PER_USER = 'MAX_PER_USER';

    public const DEFAULT_ENABLED = true;
    public const DEFAULT_BATCH_SIZE = 25;
    public const DEFAULT_MAX_BATCHES_PER_USER = 4;
    public const DEFAULT_QUIET_SECONDS = 3600;
    public const DEFAULT_TOP_K = 5;
    public const DEFAULT_MIN_SCORE = 0.5;
    public const DEFAULT_RECENCY_HALF_LIFE_DAYS = 180;
    public const DEFAULT_PULL_TOP_N = 2;
    public const DEFAULT_PULL_MIN_SCORE = 0.6;
    public const DEFAULT_BLOCK_MAX_CHARS = 4000;
    public const DEFAULT_MAX_PER_USER = 5000;

    private const MIN_BATCH_SIZE = 5;
    private const MAX_BATCH_SIZE = 100;
    private const MAX_BATCHES_CAP = 50;

    public function __construct(
        private readonly ConfigRepository $configRepository,
    ) {
    }

    public function isEnabled(): bool
    {
        $raw = $this->configRepository->getValue(0, self::CONFIG_GROUP, self::KEY_ENABLED);

        if (null === $raw || '' === $raw) {
            return self::DEFAULT_ENABLED;
        }

        return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
    }

    /** Messages per model call. */
    public function getBatchSize(): int
    {
        $value = $this->getInt(self::KEY_BATCH_SIZE, self::DEFAULT_BATCH_SIZE);

        return max(self::MIN_BATCH_SIZE, min(self::MAX_BATCH_SIZE, $value));
    }

    /** Cost cap: max model calls per user per scheduled run. */
    public function getMaxBatchesPerUser(): int
    {
        $value = $this->getInt(self::KEY_MAX_BATCHES_PER_USER, self::DEFAULT_MAX_BATCHES_PER_USER);

        return max(1, min(self::MAX_BATCHES_CAP, $value));
    }

    /**
     * Messages younger than this are left for a later run — the rolling
     * summary covers the live conversation; the digest is the long-term
     * index and must not race the chat that is still happening.
     */
    public function getQuietSeconds(): int
    {
        return max(0, $this->getInt(self::KEY_QUIET_SECONDS, self::DEFAULT_QUIET_SECONDS));
    }

    /**
     * Per-user digest cursor: the highest message id a run has already
     * SCANNED (not necessarily digested — batches that yield no key message
     * advance it too, so they are never re-billed).
     */
    public function getCursor(int $userId): int
    {
        $raw = $this->configRepository->getValue($userId, self::CONFIG_GROUP, self::KEY_CURSOR);

        return null !== $raw ? max(0, (int) $raw) : 0;
    }

    public function setCursor(int $userId, int $messageId): void
    {
        $this->configRepository->setValue($userId, self::CONFIG_GROUP, self::KEY_CURSOR, (string) $messageId);
    }

    // --- Retrieval knobs (Sprint 4) ---

    /** How many digest hits to consider per turn. */
    public function getTopK(): int
    {
        return max(1, min(20, $this->getInt(self::KEY_TOP_K, self::DEFAULT_TOP_K)));
    }

    /** Vector similarity floor for a digest to be considered at all. */
    public function getMinScore(): float
    {
        return max(0.0, min(1.0, $this->getFloat(self::KEY_MIN_SCORE, self::DEFAULT_MIN_SCORE)));
    }

    /**
     * Half-life of the recency decay: at this age a digest's effective score
     * is halved. Deliberately slow — an old but highly relevant digest (the
     * rent letter from 3 months ago) must beat a recent but vague one.
     */
    public function getRecencyHalfLifeDays(): int
    {
        return max(1, $this->getInt(self::KEY_RECENCY_HALF_LIFE_DAYS, self::DEFAULT_RECENCY_HALF_LIFE_DAYS));
    }

    /** How many top hits get their source message pulled verbatim. */
    public function getPullTopN(): int
    {
        return max(0, min(10, $this->getInt(self::KEY_PULL_TOP_N, self::DEFAULT_PULL_TOP_N)));
    }

    /** Raw-score floor a hit must clear before its message is pulled. */
    public function getPullMinScore(): float
    {
        return max(0.0, min(1.0, $this->getFloat(self::KEY_PULL_MIN_SCORE, self::DEFAULT_PULL_MIN_SCORE)));
    }

    /** Hard cap for the whole digest block (lines + pulled excerpts). */
    public function getBlockMaxChars(): int
    {
        return max(500, $this->getInt(self::KEY_BLOCK_MAX_CHARS, self::DEFAULT_BLOCK_MAX_CHARS));
    }

    /**
     * Per-user cap on ACTIVE digest entries — the digest sibling of the
     * 500-memory discipline (digests are one-liners, so the cap is higher).
     * On overflow the oldest entries are deactivated first.
     */
    public function getMaxPerUser(): int
    {
        return max(100, $this->getInt(self::KEY_MAX_PER_USER, self::DEFAULT_MAX_PER_USER));
    }

    private function getFloat(string $key, float $default): float
    {
        $raw = $this->configRepository->getValue(0, self::CONFIG_GROUP, $key);

        if (null === $raw || '' === trim($raw) || !is_numeric(trim($raw))) {
            return $default;
        }

        return (float) trim($raw);
    }

    private function getInt(string $key, int $default): int
    {
        $raw = $this->configRepository->getValue(0, self::CONFIG_GROUP, $key);

        if (null === $raw || '' === trim($raw) || !is_numeric(trim($raw))) {
            return $default;
        }

        return (int) trim($raw);
    }
}
