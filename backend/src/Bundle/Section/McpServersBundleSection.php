<?php

declare(strict_types=1);

namespace App\Bundle\Section;

use App\Bundle\BundleScope;
use App\Bundle\BundleSectionInterface;
use App\Bundle\ChecklistItem;
use App\Bundle\ImportOptions;
use App\Bundle\SectionPreview;
use App\Bundle\SectionResult;
use App\Entity\McpServerConfig;
use App\Repository\McpServerConfigRepository;
use App\Service\Tool\ToolsConfig;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.bundle.section')]
final readonly class McpServersBundleSection implements BundleSectionInterface
{
    public function __construct(
        private McpServerConfigRepository $servers,
        private ToolsConfig $toolsConfig,
    ) {
    }

    public function kind(): string
    {
        return 'mcp_servers';
    }

    public function version(): int
    {
        return 1;
    }

    public function isAvailable(?int $userId, BundleScope $scope): bool
    {
        return $this->toolsConfig->isRegistryEnabled($userId);
    }

    public function dependsOn(): array
    {
        return [];
    }

    public function export(int $userId, BundleScope $scope, array $include = []): array
    {
        $items = [];
        foreach ($this->servers->findByUser($userId) as $server) {
            $items[] = [
                'key' => $server->getName(),
                'name' => $server->getName(),
                'url' => $server->getUrl(),
                'authMode' => $server->getAuthMode(),
                'allowWrite' => $server->allowsWrite(),
                'enabled' => $server->isEnabled(),
            ];
        }

        return $items;
    }

    public function preview(array $items, int $userId): SectionPreview
    {
        $rows = [];
        foreach ($items as $item) {
            $key = (string) ($item['key'] ?? $item['name'] ?? '');
            $name = (string) ($item['name'] ?? $key);
            $existing = $this->findByName($userId, $name);
            if (null !== $existing) {
                $rows[] = new ChecklistItem('conflict', $key, $name);
            }
            $authMode = (string) ($item['authMode'] ?? McpServerConfig::AUTH_MODE_NONE);
            if (McpServerConfig::AUTH_MODE_NONE !== $authMode) {
                $rows[] = new ChecklistItem('needsCredential', $key, $name);
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
            $key = (string) ($item['key'] ?? $item['name'] ?? '');
            $name = (string) ($item['name'] ?? $key);
            try {
                if ('' === $name || '' === (string) ($item['url'] ?? '')) {
                    $skipped[] = $key;
                    continue;
                }
                $existing = $this->findByName($userId, $name);
                if (null !== $existing && ImportOptions::CONFLICT_SKIP === $options->conflict) {
                    $skipped[] = $key;
                    continue;
                }
                $server = $existing ?? new McpServerConfig();
                $server->setUserId($userId);
                $server->setName($name);
                $server->setUrl((string) $item['url']);
                $authMode = (string) ($item['authMode'] ?? McpServerConfig::AUTH_MODE_NONE);
                if (!in_array($authMode, McpServerConfig::AUTH_MODES, true)) {
                    $authMode = McpServerConfig::AUTH_MODE_NONE;
                }
                $server->setAuthMode($authMode);
                $server->setAllowWrite((bool) ($item['allowWrite'] ?? false));
                $server->setEnabled((bool) ($item['enabled'] ?? true));
                $this->servers->save($server);
                $created[] = $key;
            } catch (\Throwable $e) {
                $failed[] = ['key' => $key, 'reason' => $e->getMessage()];
            }
        }

        return new SectionResult($this->kind(), $created, $skipped, $failed);
    }

    private function findByName(int $userId, string $name): ?McpServerConfig
    {
        foreach ($this->servers->findByUser($userId) as $server) {
            if ($server->getName() === $name) {
                return $server;
            }
        }

        return null;
    }
}
