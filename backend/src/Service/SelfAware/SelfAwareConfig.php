<?php

declare(strict_types=1);

namespace App\Service\SelfAware;

use App\Repository\ConfigRepository;

/**
 * Feature-flag resolver for platform self-awareness.
 *
 * Flags live in BCONFIG group {@see self::CONFIG_GROUP}, owner 0 (global)
 * with optional per-user overrides. Resolution matches
 * {@see \App\Service\Multitask\MultitaskRoutingConfig}: per-user → owner 0 →
 * built-in default.
 */
final readonly class SelfAwareConfig
{
    public const CONFIG_GROUP = 'SELF_AWARE';

    public const KEY_ENABLED = 'ENABLED';
    public const KEY_INVENTORY_IN_GENERAL = 'INVENTORY_IN_GENERAL';
    public const KEY_DOCS_RAG_ENABLED = 'DOCS_RAG_ENABLED';
    public const KEY_DOCS_MANIFEST_URL = 'DOCS_MANIFEST_URL';
    public const KEY_DOCS_SYNC_STATE = 'DOCS_SYNC_STATE';

    public const DEFAULT_DOCS_MANIFEST_URL = 'https://docs.synaplan.com/docs-manifest.json';

    public const ROUTABLE_TOPIC = 'synaplan';

    private const DEFAULT_ENABLED = true;
    private const DEFAULT_INVENTORY_IN_GENERAL = true;
    private const DEFAULT_DOCS_RAG_ENABLED = true;

    public function __construct(
        private ConfigRepository $configRepository,
    ) {
    }

    public function isEnabled(?int $userId): bool
    {
        return $this->resolveFlag(self::KEY_ENABLED, $userId, self::DEFAULT_ENABLED);
    }

    public function isInventoryInGeneral(?int $userId): bool
    {
        return $this->resolveFlag(self::KEY_INVENTORY_IN_GENERAL, $userId, self::DEFAULT_INVENTORY_IN_GENERAL);
    }

    public function isDocsRagEnabled(?int $userId): bool
    {
        return $this->resolveFlag(self::KEY_DOCS_RAG_ENABLED, $userId, self::DEFAULT_DOCS_RAG_ENABLED);
    }

    /**
     * Manifest URL for the docs corpus sync. Empty string disables sync.
     * Not overridable per user — mirrors are an operator decision.
     */
    public function docsManifestUrl(): string
    {
        $value = $this->configRepository->getValue(0, self::CONFIG_GROUP, self::KEY_DOCS_MANIFEST_URL);
        if (null === $value) {
            return self::DEFAULT_DOCS_MANIFEST_URL;
        }

        return trim($value);
    }

    /**
     * Hide the system `synaplan` topic from a routing topic list when the
     * feature is off (invariant C1).
     *
     * @param list<array{topic: string, description?: string, ownerId?: int}|string> $topics
     *
     * @return list<array{topic: string, description?: string, ownerId?: int}|string>
     */
    public function filterRoutableTopics(array $topics, ?int $userId): array
    {
        if ($this->isEnabled($userId)) {
            return $topics;
        }

        $filtered = [];
        foreach ($topics as $topic) {
            $name = is_array($topic) ? $topic['topic'] : $topic;
            if (self::ROUTABLE_TOPIC === $name) {
                continue;
            }
            $filtered[] = $topic;
        }

        return $filtered;
    }

    private function resolveFlag(string $setting, ?int $userId, bool $default): bool
    {
        if (null !== $userId && $userId > 0) {
            $perUser = $this->configRepository->getValue($userId, self::CONFIG_GROUP, $setting);
            if (null !== $perUser) {
                return $this->toBool($perUser, $default);
            }
        }

        $global = $this->configRepository->getValue(0, self::CONFIG_GROUP, $setting);
        if (null !== $global) {
            return $this->toBool($global, $default);
        }

        return $default;
    }

    private function toBool(string $value, bool $default): bool
    {
        return filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
