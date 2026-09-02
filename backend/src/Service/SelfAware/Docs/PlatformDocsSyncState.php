<?php

declare(strict_types=1);

namespace App\Service\SelfAware\Docs;

use App\Repository\ConfigRepository;
use App\Service\SelfAware\SelfAwareConfig;

/**
 * Persists the docs-corpus sync state in BCONFIG `SELF_AWARE.DOCS_SYNC_STATE`.
 *
 * @phpstan-type PageState array{sha256: string, file_id: int, title: string, url: string, section: string, synced_at: string, slug: string}
 * @phpstan-type State array{manifest_url: string, manifest_version: string, synced_at: string, pages: array<string, PageState>}
 */
final readonly class PlatformDocsSyncState
{
    public function __construct(
        private ConfigRepository $configRepository,
    ) {
    }

    /**
     * @return State
     */
    public function read(): array
    {
        $raw = $this->configRepository->getValue(0, SelfAwareConfig::CONFIG_GROUP, SelfAwareConfig::KEY_DOCS_SYNC_STATE);
        if (null === $raw || '' === $raw) {
            return $this->empty();
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->empty();
        }
        if (!is_array($decoded)) {
            return $this->empty();
        }

        $pages = [];
        foreach ($decoded['pages'] ?? [] as $slug => $page) {
            if (!is_string($slug) || !is_array($page)) {
                continue;
            }
            $pages[$slug] = [
                'sha256' => (string) ($page['sha256'] ?? ''),
                'file_id' => (int) ($page['file_id'] ?? 0),
                'title' => (string) ($page['title'] ?? $slug),
                'url' => (string) ($page['url'] ?? ''),
                'section' => (string) ($page['section'] ?? ''),
                'synced_at' => (string) ($page['synced_at'] ?? ''),
                'slug' => $slug,
            ];
        }

        return [
            'manifest_url' => (string) ($decoded['manifest_url'] ?? ''),
            'manifest_version' => (string) ($decoded['manifest_version'] ?? ''),
            'synced_at' => (string) ($decoded['synced_at'] ?? ''),
            'pages' => $pages,
        ];
    }

    /**
     * @param State $state
     */
    public function write(array $state): void
    {
        $this->configRepository->setValue(
            0,
            SelfAwareConfig::CONFIG_GROUP,
            SelfAwareConfig::KEY_DOCS_SYNC_STATE,
            json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @return PageState|null
     */
    public function pageByFileId(int $fileId): ?array
    {
        foreach ($this->read()['pages'] as $page) {
            if ($page['file_id'] === $fileId) {
                return $page;
            }
        }

        return null;
    }

    public function hasPages(): bool
    {
        return [] !== $this->read()['pages'];
    }

    /**
     * @return State
     */
    public function empty(): array
    {
        return [
            'manifest_url' => '',
            'manifest_version' => '',
            'synced_at' => '',
            'pages' => [],
        ];
    }
}
