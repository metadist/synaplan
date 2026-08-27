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

    public const DEFAULT_ENABLED = true;
    public const DEFAULT_BATCH_SIZE = 25;
    public const DEFAULT_MAX_BATCHES_PER_USER = 4;
    public const DEFAULT_QUIET_SECONDS = 3600;

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

    private function getInt(string $key, int $default): int
    {
        $raw = $this->configRepository->getValue(0, self::CONFIG_GROUP, $key);

        if (null === $raw || '' === trim($raw) || !is_numeric(trim($raw))) {
            return $default;
        }

        return (int) trim($raw);
    }
}
