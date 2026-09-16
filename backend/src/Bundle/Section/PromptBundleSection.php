<?php

declare(strict_types=1);

namespace App\Bundle\Section;

use App\Bundle\BundleScope;
use App\Bundle\BundleSectionInterface;
use App\Bundle\ChecklistItem;
use App\Bundle\ImportOptions;
use App\Bundle\SectionPreview;
use App\Bundle\SectionResult;
use App\Entity\Agent;
use App\Entity\Model;
use App\Model\ModelCatalog;
use App\Repository\ModelRepository;
use App\Repository\PromptRepository;
use App\Service\PromptService;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.bundle.section')]
final readonly class PromptBundleSection implements BundleSectionInterface
{
    /**
     * Prompt metadata that must never cross an instance boundary: it points at
     * rows that only exist in the source install.
     */
    private const INSTANCE_LOCAL_META = ['aiModel', 'mcp_servers'];

    /** Portable replacement for the numeric `aiModel` BID. */
    private const MODEL_KEY_META = 'aiModelKey';

    public function __construct(
        private PromptRepository $prompts,
        private PromptService $promptService,
        private ModelRepository $models,
    ) {
    }

    public function kind(): string
    {
        return 'prompts';
    }

    public function version(): int
    {
        return 1;
    }

    public function isAvailable(?int $userId, BundleScope $scope): bool
    {
        return true;
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function export(int $userId, BundleScope $scope, array $include = []): array
    {
        $items = [];
        foreach ($this->prompts->findOwnedForListing($userId) as $prompt) {
            $topic = $prompt->getTopic();
            $items[] = [
                'key' => $topic,
                'topic' => $topic,
                'text' => $prompt->getPrompt(),
                'description' => $prompt->getShortDescription(),
                'meta' => $this->portableMeta($this->promptService->loadMetadataForPrompt((int) $prompt->getId())),
            ];
        }

        return $items;
    }

    public function preview(array $items, int $userId): SectionPreview
    {
        $rows = [];
        foreach ($items as $item) {
            $key = (string) ($item['key'] ?? $item['topic'] ?? '');
            $topic = (string) ($item['topic'] ?? $key);
            if (str_starts_with($topic, 'tools:') || str_starts_with($topic, Agent::TOPIC_PREFIX)) {
                $rows[] = new ChecklistItem('skipped', $key, 'internal');
                continue;
            }
            $existing = $this->prompts->findByTopicAndUser($topic, $userId);
            if (null !== $existing && $existing->getOwnerId() === $userId) {
                $rows[] = new ChecklistItem('conflict', $key, $topic);
            }
            $modelKey = self::modelKeyFromMeta(is_array($item['meta'] ?? null) ? $item['meta'] : []);
            if (null !== $modelKey && null === ModelCatalog::findBidByKey($modelKey)) {
                $rows[] = new ChecklistItem('needsModel', $key, $modelKey);
            }
        }

        return new SectionPreview($this->kind(), $rows, count($items));
    }

    public function apply(array $items, int $userId, ImportOptions $options): SectionResult
    {
        $created = [];
        $skipped = [];
        $failed = [];
        foreach ($items as $item) {
            $key = (string) ($item['key'] ?? $item['topic'] ?? '');
            $topic = (string) ($item['topic'] ?? $key);
            try {
                if ('' === $topic || str_starts_with($topic, 'tools:') || str_starts_with($topic, Agent::TOPIC_PREFIX)) {
                    $skipped[] = $key;
                    continue;
                }
                $text = is_string($item['text'] ?? null) ? $item['text'] : '';
                $description = is_string($item['description'] ?? null) ? $item['description'] : $topic;
                $existing = $this->prompts->findByTopicAndUser($topic, $userId);
                if (null !== $existing && $existing->getOwnerId() === $userId) {
                    if (ImportOptions::CONFLICT_SKIP === $options->conflict) {
                        $skipped[] = $key;
                        continue;
                    }
                    $this->promptService->overwriteOwned($existing, $text, $description);
                    $created[] = $key;
                    continue;
                }
                $prompt = $this->promptService->createOwned($userId, $topic, $text, $description);
                $meta = is_array($item['meta'] ?? null) ? $item['meta'] : [];
                $this->promptService->saveMetadataForPrompt($prompt, self::resolveMetaForImport($meta));
                $created[] = $key;
            } catch (\Throwable $e) {
                $failed[] = ['key' => $key, 'reason' => $e->getMessage()];
            }
        }

        return new SectionResult($this->kind(), $created, $skipped, $failed);
    }

    /**
     * Rewrite metadata for export: the pinned model becomes a portable
     * `service:providerId:tag` key, and every other instance-local reference
     * (MCP server ids) is dropped rather than carried to a foreign install.
     *
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private function portableMeta(array $meta): array
    {
        $modelKey = $this->modelKeyForBid($meta['aiModel'] ?? null);
        foreach (self::INSTANCE_LOCAL_META as $key) {
            unset($meta[$key]);
        }
        if (null !== $modelKey) {
            $meta[self::MODEL_KEY_META] = $modelKey;
        }

        return $meta;
    }

    /**
     * `service:providerId:tag` for a BMODELS id, or null when the model is
     * gone or the key does not resolve back to exactly one catalog entry.
     */
    private function modelKeyForBid(mixed $bid): ?string
    {
        if (!is_int($bid) && !(is_string($bid) && is_numeric($bid))) {
            return null;
        }
        $id = (int) $bid;
        if ($id < 1) {
            return null;
        }
        $model = $this->models->find($id);
        if (!$model instanceof Model) {
            return null;
        }
        $key = sprintf('%s:%s:%s', $model->getService(), $model->getProviderId(), $model->getTag());

        return null === ModelCatalog::findBidByKey($key) ? null : $key;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private static function modelKeyFromMeta(array $meta): ?string
    {
        $key = $meta[self::MODEL_KEY_META] ?? null;

        return is_string($key) && '' !== $key ? $key : null;
    }

    /**
     * Mirror of {@see portableMeta} on the way in: resolve the portable model
     * key against this install's catalog and drop it when unknown, so the
     * prompt falls back to the default model instead of pointing at whatever
     * BID the source instance happened to use.
     *
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private static function resolveMetaForImport(array $meta): array
    {
        $modelKey = self::modelKeyFromMeta($meta);
        unset($meta[self::MODEL_KEY_META]);
        foreach (self::INSTANCE_LOCAL_META as $key) {
            unset($meta[$key]);
        }
        $bid = null === $modelKey ? null : ModelCatalog::findBidByKey($modelKey);
        if (null !== $bid) {
            $meta['aiModel'] = $bid;
        }

        return $meta;
    }
}
