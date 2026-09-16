<?php

declare(strict_types=1);

namespace App\Service\Message\Routing;

use App\Repository\ConfigRepository;

/**
 * Feature flag and confidence threshold for the embedding-router cascade
 * layer.
 *
 * Flags live in BCONFIG group {@see self::CONFIG_GROUP}:
 *   - ENABLED — master switch. Default OFF, mirroring
 *     {@see \App\Service\Message\MessageClassifier::isClassifierFastPathEnabled()}:
 *     a semantic-similarity shortcut that mis-routes is worse than the extra
 *     100-800 ms of an AI-sorter round-trip, so this only goes on after
 *     `app:sort-eval --cascade` shows the embedding layer matches or beats
 *     the sorter baseline on the four SYSTEM topics.
 *   - CONFIDENCE_THRESHOLD — minimum cosine similarity (0.0-1.0) required to
 *     short-circuit the AI sorter. Deliberately NOT guessed: a threshold
 *     picked in code is the main way this layer can silently misroute, so it
 *     is calibrated via `app:sort-eval --cascade` against the labelled
 *     corpus.
 *
 * Resolution mirrors {@see \App\AI\StructuredOutput\StructuredOutputConfig}:
 * a per-user ENABLED row (BOWNERID = userId) overrides the global row
 * (BOWNERID = 0), which overrides the built-in default. The threshold is
 * deliberately global-only — a per-user confidence threshold would make eval
 * numbers incomparable across accounts.
 */
final readonly class EmbeddingRouterConfig
{
    public const CONFIG_GROUP = 'EMBEDDING_ROUTER';
    public const KEY_ENABLED = 'ENABLED';
    public const KEY_CONFIDENCE_THRESHOLD = 'CONFIDENCE_THRESHOLD';

    private const DEFAULT_ENABLED = false;

    /**
     * Conservative starting point, not a measured value — see the class
     * docblock. Operators MUST recalibrate via `app:sort-eval --cascade`
     * before switching ENABLED on for real traffic.
     */
    private const DEFAULT_CONFIDENCE_THRESHOLD = 0.88;

    public function __construct(
        private ConfigRepository $configRepository,
    ) {
    }

    /**
     * Master switch. Per-user override wins, then global, then built-in
     * default (OFF).
     */
    public function isEnabled(?int $userId): bool
    {
        if (null !== $userId && $userId > 0) {
            $perUser = $this->configRepository->getValue($userId, self::CONFIG_GROUP, self::KEY_ENABLED);
            if (null !== $perUser) {
                return $this->toBool($perUser);
            }
        }

        $global = $this->configRepository->getValue(0, self::CONFIG_GROUP, self::KEY_ENABLED);
        if (null !== $global) {
            return $this->toBool($global);
        }

        return self::DEFAULT_ENABLED;
    }

    /**
     * Minimum cosine similarity required to trust an embedding-router match.
     * Clamped to [0.0, 1.0] so a malformed BCONFIG row cannot disable the
     * threshold entirely (a value <= 0 would let every match through).
     */
    public function getConfidenceThreshold(): float
    {
        $raw = $this->configRepository->getValue(0, self::CONFIG_GROUP, self::KEY_CONFIDENCE_THRESHOLD);
        if (null === $raw || '' === trim($raw) || !is_numeric(trim($raw))) {
            return self::DEFAULT_CONFIDENCE_THRESHOLD;
        }

        return max(0.0, min(1.0, (float) trim($raw)));
    }

    private function toBool(string $value): bool
    {
        return filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? self::DEFAULT_ENABLED;
    }
}
