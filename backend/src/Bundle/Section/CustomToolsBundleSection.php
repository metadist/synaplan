<?php

declare(strict_types=1);

namespace App\Bundle\Section;

use App\Bundle\BundleScope;
use App\Bundle\BundleSectionInterface;
use App\Bundle\ChecklistItem;
use App\Bundle\ImportOptions;
use App\Bundle\SectionPreview;
use App\Bundle\SectionResult;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Tool\Custom\CustomToolService;
use App\Service\Tool\Custom\InvalidToolTemplateException;
use App\Service\Tool\ToolsConfig;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.bundle.section')]
final readonly class CustomToolsBundleSection implements BundleSectionInterface
{
    public function __construct(
        private CustomToolService $customTools,
        private ToolsConfig $toolsConfig,
        private UserRepository $users,
    ) {
    }

    public function kind(): string
    {
        return 'custom_tools';
    }

    public function version(): int
    {
        return 1;
    }

    public function isAvailable(?int $userId, BundleScope $scope): bool
    {
        return $this->toolsConfig->isCustomHttpEnabled($userId);
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function export(int $userId, BundleScope $scope, array $include = []): array
    {
        $user = $this->users->find($userId);
        if (!$user instanceof User) {
            return [];
        }
        $items = [];
        foreach ($this->customTools->listFor($user) as $tool) {
            unset($tool['credentialId'], $tool['id'], $tool['created'], $tool['updated'], $tool['registryName']);
            $spec = is_array($tool['spec'] ?? null) ? $tool['spec'] : [];
            $needsCredential = $this->specNeedsCredential($spec);
            $items[] = [
                'key' => (string) $tool['name'],
                'name' => $tool['name'],
                'title' => $tool['title'],
                'description' => $tool['description'],
                'type' => $tool['type'],
                'sideEffect' => $tool['sideEffect'],
                'spec' => $spec,
                'inputSchema' => $tool['inputSchema'],
                'sourceRef' => $tool['sourceRef'],
                'enabled' => $needsCredential ? false : $tool['enabled'],
            ];
        }

        return $items;
    }

    public function preview(array $items, int $userId): SectionPreview
    {
        $user = $this->users->find($userId);
        $rows = [];
        $owned = [];
        if ($user instanceof User) {
            foreach ($this->customTools->listFor($user) as $tool) {
                $owned[(string) $tool['name']] = true;
            }
        }
        foreach ($items as $item) {
            $key = (string) ($item['key'] ?? $item['name'] ?? '');
            $name = (string) ($item['name'] ?? $key);
            if (isset($owned[$name])) {
                $rows[] = new ChecklistItem('conflict', $key, $name);
            }
            $spec = is_array($item['spec'] ?? null) ? $item['spec'] : [];
            if ($this->specNeedsCredential($spec)) {
                $rows[] = new ChecklistItem('needsCredential', $key, $name);
            }
        }

        return new SectionPreview($this->kind(), $rows, count($items));
    }

    public function apply(array $items, int $userId, ImportOptions $options): SectionResult
    {
        $user = $this->users->find($userId);
        if (!$user instanceof User) {
            return new SectionResult($this->kind(), failed: [['key' => '*', 'reason' => 'User not found']]);
        }
        $created = [];
        $skipped = [];
        $failed = [];
        foreach ($items as $item) {
            $key = (string) ($item['key'] ?? $item['name'] ?? '');
            try {
                $payload = [
                    'name' => $item['name'] ?? $key,
                    'title' => $item['title'] ?? $key,
                    'description' => $item['description'] ?? null,
                    'type' => $item['type'] ?? 'http',
                    'sideEffect' => $item['sideEffect'] ?? 'write',
                    'spec' => is_array($item['spec'] ?? null) ? $item['spec'] : [],
                    'inputSchema' => is_array($item['inputSchema'] ?? null) ? $item['inputSchema'] : null,
                    'sourceRef' => is_string($item['sourceRef'] ?? null) ? $item['sourceRef'] : null,
                    'enabled' => $this->specNeedsCredential(is_array($item['spec'] ?? null) ? $item['spec'] : [])
                        ? false
                        : (bool) ($item['enabled'] ?? true),
                ];
                $this->customTools->create($user, $payload);
                $created[] = $key;
            } catch (InvalidToolTemplateException $e) {
                if (ImportOptions::CONFLICT_SKIP === $options->conflict && str_contains($e->getMessage(), 'already exists')) {
                    $skipped[] = $key;
                    continue;
                }
                $failed[] = ['key' => $key, 'reason' => $e->getMessage()];
            } catch (\Throwable $e) {
                $failed[] = ['key' => $key, 'reason' => $e->getMessage()];
            }
        }

        return new SectionResult($this->kind(), $created, $skipped, $failed);
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function specNeedsCredential(array $spec): bool
    {
        $encoded = json_encode($spec);

        return is_string($encoded) && str_contains($encoded, '{{credential.header}}');
    }
}
