<?php

declare(strict_types=1);

namespace App\Service\SavedTask\Graph;

use App\Entity\InboundEmailHandler;
use App\Entity\Prompt;
use App\Entity\SavedTask;
use App\Repository\InboundEmailHandlerRepository;
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

    public const MISSING_MCP_SERVER = 'missing-connection';

    public function __construct(
        private PromptRepository $prompts,
        private McpServerConfigRepository $mcpServers,
        private ?InboundEmailHandlerRepository $inboundEmail = null,
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
        unset(
            $config['token'],
            $config['hmacSecret'],
            $config['hmacConfigured'],
            $config['accountId'],
            $config['agentId'],
            $config['agentTrigger'],
        );

        return $config;
    }

    /**
     * Strip secrets and owner-bound mailbox ids, then remap inbound email
     * when the destination user already owns that mailbox (or exactly one).
     *
     * @param array<string, mixed>|null $config
     *
     * @return array{config: array<string, mixed>|null, checklist: list<ChecklistRow>}
     */
    public function importTriggerConfig(?array $config, string $triggerType, int $userId): array
    {
        $accountId = is_array($config) ? ($config['accountId'] ?? null) : null;
        $config = $this->exportTriggerConfig($config);
        if (SavedTask::TRIGGER_INBOUND_EMAIL !== $triggerType) {
            return ['config' => $config, 'checklist' => []];
        }

        $config = is_array($config) ? $config : [];
        $resolved = $this->resolveInboundAccount($accountId, $userId);
        if (null !== $resolved) {
            $config['accountId'] = $resolved;

            return ['config' => $config, 'checklist' => []];
        }

        return [
            'config' => $config,
            'checklist' => [['code' => 'needsMailbox', 'itemKey' => 'inbound_email', 'detail' => 'inbound_email']],
        ];
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
                $topic = $this->chatTopicFromParams($params);
                if ('' !== $topic) {
                    $params['prompt_topic'] = $topic;
                    $params['topic_id'] = $topic;
                    unset($params['prompt_id']);
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
                $topic = $this->chatTopicFromParams($params);
                if ('' !== $topic) {
                    $params['topic_id'] = $topic;
                    $prompt = $this->usablePromptByTopic($topic, $userId);
                    if ($prompt instanceof Prompt) {
                        $params['prompt_id'] = (string) $prompt->getId();
                        unset($params['prompt_topic']);
                    } else {
                        $params['prompt_topic'] = $topic;
                        unset($params['prompt_id']);
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
            $fallback = is_numeric($promptId) ? $this->prompts->find((int) $promptId) : null;
            if ($fallback instanceof Prompt) {
                $params['topic_id'] = $fallback->getTopic();
            }
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

    /**
     * @param array<string, mixed> $params
     */
    private function chatTopicFromParams(array $params): string
    {
        foreach (['prompt_topic', 'topic_id'] as $key) {
            $value = $params[$key] ?? null;
            if (is_string($value) && '' !== trim($value) && !ctype_digit(trim($value))) {
                return trim($value);
            }
        }
        $promptId = $params['prompt_id'] ?? null;
        if (is_numeric($promptId)) {
            $prompt = $this->prompts->find((int) $promptId);
            if ($prompt instanceof Prompt) {
                return $prompt->getTopic();
            }
        }

        return '';
    }

    public function usablePromptByTopic(string $topic, int $userId): ?Prompt
    {
        $prompt = $this->prompts->findByTopicAndUser($topic, $userId);

        return $prompt instanceof Prompt && $prompt->isEnabled() ? $prompt : null;
    }

    private function exportToolName(string $tool, int $ownerId): string
    {
        if (1 !== preg_match('/^mcp:(\d+):(.+)$/', $tool, $m)) {
            return $tool;
        }
        $server = $this->mcpServers->findByIdAndUser((int) $m[1], $ownerId);

        return null !== $server
            ? 'mcp:'.$server->getName().':'.$m[2]
            : 'mcp:'.self::MISSING_MCP_SERVER.':'.$m[2];
    }

    /**
     * @return array{tool: string, checklist: list<ChecklistRow>}
     */
    private function importToolName(string $tool, int $userId): array
    {
        if (1 !== preg_match('/^mcp:([^:]+):(.+)$/', $tool, $m)) {
            return ['tool' => $tool, 'checklist' => []];
        }
        $serverName = $m[1];
        $toolName = $m[2];
        if (ctype_digit($serverName) || self::MISSING_MCP_SERVER === $serverName) {
            return [
                'tool' => 'mcp:'.self::MISSING_MCP_SERVER.':'.$toolName,
                'checklist' => [['code' => 'needsConnection', 'itemKey' => self::MISSING_MCP_SERVER, 'detail' => self::MISSING_MCP_SERVER]],
            ];
        }
        $server = $this->mcpServers->findByUserAndName($userId, $serverName);
        if (null === $server || !$server->isEnabled()) {
            return [
                'tool' => 'mcp:'.$serverName.':'.$toolName,
                'checklist' => [['code' => 'needsConnection', 'itemKey' => $serverName, 'detail' => $serverName]],
            ];
        }

        return ['tool' => sprintf('mcp:%d:%s', (int) $server->getId(), $toolName), 'checklist' => []];
    }

    private function resolveInboundAccount(mixed $accountId, int $userId): ?int
    {
        if (null === $this->inboundEmail) {
            return null;
        }
        $id = is_numeric($accountId) ? (int) $accountId : 0;
        if ($id > 0) {
            $owned = $this->inboundEmail->findByIdAndUser($id, $userId);
            if ($owned instanceof InboundEmailHandler) {
                return $id;
            }
        }
        $active = $this->inboundEmail->findActiveByUser($userId);
        if (1 !== count($active) || !$active[0] instanceof InboundEmailHandler) {
            return null;
        }
        $only = $active[0]->getId();

        return is_numeric($only) ? (int) $only : null;
    }
}
