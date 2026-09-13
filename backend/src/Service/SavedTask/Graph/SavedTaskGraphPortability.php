<?php

declare(strict_types=1);

namespace App\Service\SavedTask\Graph;

use App\Entity\Prompt;
use App\Repository\McpServerConfigRepository;
use App\Repository\PromptRepository;
use App\Service\Multitask\Plan\Capability;

/**
 * Rewrites a Saved Task graph for copy / bundle export: no secrets, prompts
 * by topic, MCP tools by server name. Import reverses the MCP rewrite.
 *
 * @phpstan-type ChecklistRow array{code: string, itemKey: string, detail: string|null}
 */
final readonly class SavedTaskGraphPortability
{
    public const ITEM_KEYS = ['key', 'name', 'prompt', 'triggerType', 'triggerConfig', 'graph', 'settings'];

    public function __construct(
        private PromptRepository $prompts,
        private McpServerConfigRepository $mcpServers,
    ) {
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return list<string>
     */
    public function unknownItemKeys(array $item): array
    {
        $unknown = [];
        foreach (array_keys($item) as $key) {
            if (!in_array($key, self::ITEM_KEYS, true)) {
                $unknown[] = $key;
            }
        }

        return $unknown;
    }

    /**
     * @param array<string, mixed>|null $config
     *
     * @return array<string, mixed>|null
     */
    public function exportTriggerConfig(?array $config): ?array
    {
        if (null === $config) {
            return null;
        }
        unset($config['token'], $config['hmacSecret'], $config['hmacConfigured']);

        return $config;
    }

    /**
     * @param array<string, mixed>|null $graph
     *
     * @return array<string, mixed>|null
     */
    public function exportGraph(?array $graph, int $ownerId): ?array
    {
        if (null === $graph) {
            return null;
        }
        $graph = $this->stripOutboundSecrets($graph);
        if (!is_array($graph['nodes'] ?? null)) {
            return $graph;
        }
        foreach ($graph['nodes'] as $i => $node) {
            if (!is_array($node)) {
                continue;
            }
            $params = is_array($node['params'] ?? null) ? $node['params'] : [];
            if (Capability::Chat->value === ($node['capability'] ?? null)) {
                $promptId = $params['prompt_id'] ?? null;
                if (is_numeric($promptId)) {
                    $prompt = $this->prompts->find((int) $promptId);
                    if ($prompt instanceof Prompt) {
                        $params['prompt_topic'] = $prompt->getTopic();
                        unset($params['prompt_id']);
                    }
                }
            }
            if (Capability::ToolCall->value === ($node['capability'] ?? null)) {
                $tool = is_string($params['tool'] ?? null) ? $params['tool'] : '';
                $params['tool'] = $this->exportToolName($tool, $ownerId);
            }
            $graph['nodes'][$i]['params'] = $params;
        }

        return $graph;
    }

    /**
     * @param array<string, mixed>|null $graph
     *
     * @return array{graph: array<string, mixed>|null, checklist: list<ChecklistRow>}
     */
    public function importGraph(?array $graph, int $userId): array
    {
        $checklist = [];
        if (null === $graph) {
            return ['graph' => null, 'checklist' => []];
        }
        $graph = $this->stripOutboundSecrets($graph);
        if (!is_array($graph['nodes'] ?? null)) {
            return ['graph' => $graph, 'checklist' => []];
        }
        foreach ($graph['nodes'] as $i => $node) {
            if (!is_array($node)) {
                continue;
            }
            $params = is_array($node['params'] ?? null) ? $node['params'] : [];
            if (Capability::Chat->value === ($node['capability'] ?? null)) {
                $topic = is_string($params['prompt_topic'] ?? null) ? $params['prompt_topic'] : '';
                if ('' !== $topic) {
                    $prompt = $this->prompts->findByTopicAndUser($topic, $userId);
                    if ($prompt instanceof Prompt) {
                        $params['prompt_id'] = (string) $prompt->getId();
                        unset($params['prompt_topic']);
                    } else {
                        $checklist[] = ['code' => 'needsAssistant', 'itemKey' => $topic, 'detail' => $topic];
                    }
                }
            }
            if (Capability::ToolCall->value === ($node['capability'] ?? null)) {
                $tool = is_string($params['tool'] ?? null) ? $params['tool'] : '';
                $imported = $this->importToolName($tool, $userId);
                $params['tool'] = $imported['tool'];
                $checklist = array_merge($checklist, $imported['checklist']);
            }
            $graph['nodes'][$i]['params'] = $params;
        }

        return ['graph' => $graph, 'checklist' => $checklist];
    }

    /**
     * @param array<string, mixed> $graph
     *
     * @return array<string, mixed>
     */
    public function stripOutboundSecrets(array $graph): array
    {
        if (!is_array($graph['nodes'] ?? null)) {
            return $graph;
        }
        foreach ($graph['nodes'] as $i => $node) {
            if (is_array($node) && Capability::OutboundWebhook->value === ($node['capability'] ?? null) && is_array($node['params'] ?? null)) {
                unset($graph['nodes'][$i]['params']['secret'], $graph['nodes'][$i]['params']['secretConfigured']);
            }
        }

        return $graph;
    }

    /**
     * @param array<string, mixed> $graph
     *
     * @return array<string, mixed>
     */
    public function retargetChatPrompt(array $graph, string $promptId): array
    {
        if (!is_array($graph['nodes'] ?? null)) {
            return $graph;
        }
        foreach ($graph['nodes'] as $i => $node) {
            if (!is_array($node) || Capability::Chat->value !== ($node['capability'] ?? null)) {
                continue;
            }
            $params = is_array($node['params'] ?? null) ? $node['params'] : [];
            $params['prompt_id'] = $promptId;
            unset($params['prompt_topic']);
            $graph['nodes'][$i]['params'] = $params;
        }

        return $graph;
    }

    /**
     * @return list<string>
     */
    public function toolNames(array $graph): array
    {
        $names = [];
        foreach (is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [] as $node) {
            if (!is_array($node) || Capability::ToolCall->value !== ($node['capability'] ?? null)) {
                continue;
            }
            $params = is_array($node['params'] ?? null) ? $node['params'] : [];
            $tool = is_string($params['tool'] ?? null) ? trim($params['tool']) : '';
            if ('' !== $tool) {
                $names[] = $tool;
            }
        }

        return $names;
    }

    private function exportToolName(string $tool, int $ownerId): string
    {
        if (1 !== preg_match('/^mcp:(\d+):(.+)$/', $tool, $m)) {
            return $tool;
        }
        $server = $this->mcpServers->findByIdAndUser((int) $m[1], $ownerId);

        return null !== $server ? 'mcp:'.$server->getName().':'.$m[2] : $tool;
    }

    /**
     * @return array{tool: string, checklist: list<ChecklistRow>}
     */
    private function importToolName(string $tool, int $userId): array
    {
        if (1 !== preg_match('/^mcp:([^:]+):(.+)$/', $tool, $m) || ctype_digit($m[1])) {
            return ['tool' => $tool, 'checklist' => []];
        }
        $server = $this->mcpServers->findByUserAndName($userId, $m[1]);
        if (null === $server) {
            return [
                'tool' => $tool,
                'checklist' => [['code' => 'needsConnection', 'itemKey' => $m[1], 'detail' => $m[1]]],
            ];
        }

        return ['tool' => sprintf('mcp:%d:%s', (int) $server->getId(), $m[2]), 'checklist' => []];
    }
}
