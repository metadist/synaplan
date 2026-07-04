<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Entity\McpServerConfig;
use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\McpClient;
use App\Service\Mcp\McpClientConfig;
use App\Service\Mcp\McpClientException;
use App\Service\Mcp\McpToolRegistry;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Multitask\Skill\SkillDescriptor;
use App\Service\PromptService;
use Psr\Log\LoggerInterface;

/**
 * `mcp_fetch` runner — pull data from one of the user's connected external
 * MCP servers inside the DAG (release-4.0 plan 09 §3.2, the customer
 * deliverable; node shape = locked Option A: one generic capability, the
 * server/tool live in `params`, the tool arguments in `inputs.arguments`).
 *
 * The FIRST dynamic skill block: its planner-facing description expands into
 * the per-user tool sub-catalog at plan time (via {@see describe()}'s dynamic
 * note), and only when the matched topic opted in via the `tool_mcp` prompt
 * metadata — a topic without the flag never even shows the planner that MCP
 * tools exist.
 *
 * Gate chain (each re-checked at run time, defense in depth):
 *   MCP.CLIENT_ENABLED (master) → MULTITASK.MCP_FETCH_ENABLED (routing flag)
 *   → topic `tool_mcp` (+ optional `mcp_servers` allowlist) → server owned by
 *   the user + enabled → tool exists on the server → tool not self-declared
 *   mutating (pull-only v1). A hallucinated or stale plan can never reach a
 *   server the topic isn't entitled to.
 */
final readonly class McpFetchRunner implements TaskRunner
{
    /** Cap on the formatted node output (token control, like url_fetch). */
    private const MAX_OUTPUT_CHARS = 12000;

    public function __construct(
        private McpClient $client,
        private McpToolRegistry $toolRegistry,
        private McpServerConfigRepository $servers,
        private McpClientConfig $clientConfig,
        private MultitaskRoutingConfig $routingConfig,
        private PromptService $promptService,
        private LoggerInterface $logger,
    ) {
    }

    public function supportedCapabilities(): array
    {
        return [Capability::McpFetch];
    }

    /**
     * @return list<SkillDescriptor>
     */
    public function describe(): array
    {
        return [
            new SkillDescriptor(
                Capability::McpFetch,
                'Pull data from one of the user\'s connected external systems (read-only) before answering. Set params.server_id and params.tool from the connections listed below; pass the tool arguments in inputs.arguments. ONLY use a server/tool listed below — if none fits, do NOT emit mcp_fetch.',
                dynamicNote: fn (?int $userId, array $context): ?string => $this->renderToolSubCatalog($userId, $context),
                enabledFlag: MultitaskRoutingConfig::KEY_MCP_FETCH_ENABLED,
                enabledDefault: false,
                requiresDynamicNote: true,
            ),
        ];
    }

    public function run(TaskNode $node, NodeContext $context): NodeResult
    {
        $userId = $context->userId ?? $context->message->getUserId();

        // Flags (defense in depth — the catalog already hid the block).
        if (!$this->clientConfig->isClientEnabled($userId)
            || !$this->routingConfig->isFeatureEnabled(MultitaskRoutingConfig::KEY_MCP_FETCH_ENABLED, $userId, false)) {
            return NodeResult::failed('mcp_fetch is disabled');
        }

        $serverId = is_numeric($node->params['server_id'] ?? null) ? (int) $node->params['server_id'] : 0;
        $tool = is_string($node->params['tool'] ?? null) ? trim($node->params['tool']) : '';
        if ($serverId <= 0 || '' === $tool) {
            return NodeResult::failed('mcp_fetch needs params.server_id and params.tool');
        }

        // Ownership + enabled — cross-tenant access is structurally impossible.
        $server = $this->servers->findByIdAndUser($serverId, (int) $userId);
        if (null === $server || !$server->isEnabled()) {
            return NodeResult::failed('this data connection is not available');
        }

        // Topic entitlement re-check (plan 09 §3.2 run-time gate).
        if (!$this->topicAllowsServer($userId, $context->classification, $serverId)) {
            return NodeResult::failed('this topic is not allowed to use MCP data sources');
        }

        // The tool must actually exist on the server (hallucination guard) and
        // must not declare itself mutating (pull-only v1, plan 09 §2.4).
        $catalogTool = $this->findTool($server, $tool);
        if (null === $catalogTool) {
            return NodeResult::failed(sprintf("the tool '%s' does not exist on this connection", $tool));
        }
        if ($this->isMutatingTool($catalogTool['annotations'])) {
            return NodeResult::failed(sprintf("the tool '%s' can modify data and is not allowed (read-only)", $tool));
        }

        $arguments = $this->resolveArguments($node, $context);

        try {
            $result = $this->client->callTool($server, $tool, $arguments);
        } catch (McpClientException $e) {
            $this->logger->warning('McpFetchRunner: tool call failed', [
                'server_id' => $serverId,
                'tool' => $tool,
                'error' => $e->getMessage(),
            ]);

            return NodeResult::failed('could not reach the data source: '.$e->getMessage());
        }

        $text = $this->formatContent($result['content']);
        if ($result['isError']) {
            return NodeResult::failed('the data source reported an error: '.mb_substr($text, 0, 300));
        }
        if ('' === trim($text)) {
            return NodeResult::failed('the data source returned no usable content');
        }

        $this->logger->info('McpFetchRunner: tool call succeeded', [
            'server_id' => $serverId,
            'tool' => $tool,
            'chars' => mb_strlen($text),
        ]);

        return NodeResult::ok($text, [], [
            'mcp' => ['server_id' => $serverId, 'server' => $server->getName(), 'tool' => $tool],
            // Compact summary line for the search-style task card.
            'query' => $server->getName().' · '.$tool,
        ]);
    }

    /**
     * The per-user tool sub-catalog injected under the capability summary —
     * only when every plan-time gate passes; null keeps the whole block
     * invisible ({@see SkillDescriptor::$requiresDynamicNote}).
     *
     * @param array<string, mixed> $context
     */
    private function renderToolSubCatalog(?int $userId, array $context): ?string
    {
        if (null === $userId || $userId <= 0 || !$this->clientConfig->isClientEnabled($userId)) {
            return null;
        }

        $topicMetadata = is_array($context['topic_metadata'] ?? null) ? $context['topic_metadata'] : [];
        if (true !== ($topicMetadata['tool_mcp'] ?? null)) {
            return null;
        }
        $allowlist = $this->serverAllowlist($topicMetadata);

        $lines = [];
        foreach ($this->toolRegistry->catalogForUser($userId) as $entry) {
            $server = $entry['server'];
            $serverId = (int) $server->getId();
            if (null !== $allowlist && !in_array($serverId, $allowlist, true)) {
                continue;
            }

            $tools = [];
            foreach ($entry['tools'] as $tool) {
                if ($this->isMutatingTool($tool['annotations'])) {
                    continue;
                }
                $tools[] = $tool['name'].$this->renderArgumentHint($tool['inputSchema'])
                    .('' !== $tool['description'] ? ' — '.$this->oneLine($tool['description']) : '');
            }
            if ([] === $tools) {
                continue;
            }

            $lines[] = sprintf('    • server_id %d "%s" — tools:', $serverId, $server->getName());
            foreach ($tools as $toolLine) {
                $lines[] = '      - '.$toolLine;
            }
        }

        if ([] === $lines) {
            return null;
        }

        return "  Available connections for this user:\n".implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $classification
     */
    private function topicAllowsServer(?int $userId, array $classification, int $serverId): bool
    {
        $topic = is_string($classification['topic'] ?? null) ? $classification['topic'] : '';
        if ('' === $topic) {
            return false;
        }

        try {
            $promptData = $this->promptService->getPromptWithMetadata($topic, (int) $userId);
        } catch (\Throwable) {
            return false;
        }
        $metadata = is_array($promptData['metadata'] ?? null) ? $promptData['metadata'] : [];

        if (true !== ($metadata['tool_mcp'] ?? null)) {
            return false;
        }

        $allowlist = $this->serverAllowlist($metadata);

        return null === $allowlist || in_array($serverId, $allowlist, true);
    }

    /**
     * Parse the optional `mcp_servers` prompt metadata (comma-separated
     * McpServerConfig ids). Null = no restriction (all connected servers).
     *
     * @param array<string, mixed> $topicMetadata
     *
     * @return list<int>|null
     */
    private function serverAllowlist(array $topicMetadata): ?array
    {
        $raw = $topicMetadata['mcp_servers'] ?? null;
        if (!is_string($raw) || '' === trim($raw)) {
            return null;
        }

        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if (is_numeric($part) && (int) $part > 0) {
                $ids[] = (int) $part;
            }
        }

        return [] === $ids ? null : $ids;
    }

    /**
     * @return array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>}|null
     */
    private function findTool(McpServerConfig $server, string $tool): ?array
    {
        foreach ($this->toolRegistry->toolsFor($server) as $candidate) {
            if ($candidate['name'] === $tool) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Pull-only v1: refuse tools that DECLARE themselves mutating via the
     * spec's tool annotations. Tools without annotations are allowed — most
     * read tools don't carry them, and the planner catalog only offered
     * read-safe entries in the first place.
     *
     * @param array<string, mixed> $annotations
     */
    private function isMutatingTool(array $annotations): bool
    {
        if (false === ($annotations['readOnlyHint'] ?? null)) {
            return true;
        }

        return true === ($annotations['destructiveHint'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveArguments(TaskNode $node, NodeContext $context): array
    {
        $inputs = $context->resolveInputs($node);
        $arguments = $inputs['arguments'] ?? null;

        if (is_array($arguments)) {
            return $arguments;
        }
        if (is_string($arguments) && '' !== trim($arguments)) {
            $decoded = json_decode($arguments, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Flatten the tool result's content blocks into one citable text block
     * for downstream `$nX.text` consumers (plan 09 §2.7 uniform shape).
     *
     * @param list<array<string, mixed>> $content
     */
    private function formatContent(array $content): string
    {
        $parts = [];
        foreach ($content as $block) {
            $type = $block['type'] ?? null;
            if ('text' === $type && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            } elseif (is_array($block['resource'] ?? null) && is_string($block['resource']['text'] ?? null)) {
                $parts[] = $block['resource']['text'];
            }
        }

        $text = trim(implode("\n\n", $parts));
        if (mb_strlen($text) > self::MAX_OUTPUT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_OUTPUT_CHARS).'…';
        }

        return $text;
    }

    /**
     * Compact `(arg1, arg2, …)` hint from a tool's JSON input schema.
     *
     * @param array<string, mixed> $inputSchema
     */
    private function renderArgumentHint(array $inputSchema): string
    {
        $properties = is_array($inputSchema['properties'] ?? null) ? array_keys($inputSchema['properties']) : [];
        if ([] === $properties) {
            return '';
        }

        return '('.implode(', ', array_slice(array_map('strval', $properties), 0, 6)).')';
    }

    private function oneLine(string $text): string
    {
        $line = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($line) > 140 ? mb_substr($line, 0, 137).'…' : $line;
    }
}
