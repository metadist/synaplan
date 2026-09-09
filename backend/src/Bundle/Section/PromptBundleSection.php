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
use App\Model\ModelCatalog;
use App\Repository\PromptRepository;
use App\Service\PromptService;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.bundle.section')]
final readonly class PromptBundleSection implements BundleSectionInterface
{
    public function __construct(
        private PromptRepository $prompts,
        private PromptService $promptService,
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
                'meta' => $this->promptService->loadMetadataForPrompt((int) $prompt->getId()),
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
            foreach ($this->modelKeysFromMeta(is_array($item['meta'] ?? null) ? $item['meta'] : []) as $capability => $modelKey) {
                if (null === ModelCatalog::findBidByKey($modelKey)) {
                    $rows[] = new ChecklistItem('needsModel', $key, $capability);
                }
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
                $this->promptService->saveMetadataForPrompt($prompt, $this->filterResolvableMeta($meta));
                $created[] = $key;
            } catch (\Throwable $e) {
                $failed[] = ['key' => $key, 'reason' => $e->getMessage()];
            }
        }

        return new SectionResult($this->kind(), $created, $skipped, $failed);
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, string>
     */
    private function modelKeysFromMeta(array $meta): array
    {
        $out = [];
        foreach (['ai_chat', 'ai_quality', 'ai_cheap'] as $capability) {
            $value = $meta[$capability] ?? null;
            if (is_string($value) && str_contains($value, ':')) {
                $out[$capability] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private function filterResolvableMeta(array $meta): array
    {
        $out = [];
        foreach ($meta as $key => $value) {
            if (is_string($value) && str_contains($value, ':') && str_starts_with($key, 'ai_')) {
                if (null === ModelCatalog::findBidByKey($value)) {
                    continue;
                }
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
