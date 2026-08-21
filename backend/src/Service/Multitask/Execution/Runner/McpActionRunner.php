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
 * `mcp_action` runner — performs a WRITE action (create/update) on one of the
 * user's connected external MCP servers, e.g. "create a Confluence page about
 * X" or "open a Jira ticket for Y". The write-side sibling of
 * {@see McpFetchRunner} (same locked node shape: server/tool in `params`, tool
 * arguments in `inputs.arguments`).
 *
 * Writes are double-opt-in: beyond the read gate chain, the SERVER OWNER must
 * have flipped `allow_write` on the connection (`BMCPSERVERS.BALLOWWRITE`) —
 * a server without the flag never appears in the planner sub-catalog and is
 * refused at run time. Tools that declare themselves DESTRUCTIVE
 * (`destructiveHint: true` — deletes, irreversible updates) stay refused
 * entirely in v1; creation/update tools are the scope of this capability.
 *
 * Gate chain (each re-checked at run time, defense in depth):
 *   MCP.CLIENT_ENABLED → MULTITASK.MCP_ACTION_ENABLED → topic `tool_mcp`
 *   (+ optional `mcp_servers` allowlist) → server owned + enabled +
 *   `allow_write` → tool exists on the server → tool not destructive.
 */
final readonly class McpActionRunner implements TaskRunner
{
    /** Cap on the formatted node output (token control, like mcp_fetch). */
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
        return [Capability::McpAction];
    }

    /**
     * @return list<SkillDescriptor>
     */
    public function describe(): array
    {
        return [
            new SkillDescriptor(
                Capability::McpAction,
                'Perform a WRITE action on one of the user\'s connected external systems — e.g. create a Confluence page, create a Jira ticket. ONLY when the user explicitly asks to create/update something there. Set params.server_id and params.tool from the write-enabled connections listed below; pass the tool arguments in inputs.arguments. ONLY use a server/tool listed below — if none fits, do NOT emit mcp_action.',
                dynamicNote: fn (?int $userId, array $context): ?string => $this->renderToolSubCatalog($userId, $context),
                enabledFlag: MultitaskRoutingConfig::KEY_MCP_ACTION_ENABLED,
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
            || !$this->routingConfig->isFeatureEnabled(MultitaskRoutingConfig::KEY_MCP_ACTION_ENABLED, $userId, false)) {
            return NodeResult::failed('mcp_action is disabled');
        }

        $serverId = is_numeric($node->params['server_id'] ?? null) ? (int) $node->params['server_id'] : 0;
        $tool = is_string($node->params['tool'] ?? null) ? trim($node->params['tool']) : '';
        if ($serverId <= 0 || '' === $tool) {
            return NodeResult::failed('mcp_action needs params.server_id and params.tool');
        }

        // Ownership + enabled + the write opt-in — a server whose owner never
        // flipped `allow_write` is structurally unreachable for writes.
        $server = $this->servers->findByIdAndUser($serverId, (int) $userId);
        if (null === $server || !$server->isEnabled()) {
            return NodeResult::failed('this connection is not available');
        }
        if (!$server->allowsWrite()) {
            return NodeResult::failed('this connection does not allow write actions — enable "allow write actions" on the MCP server first');
        }

        // Topic entitlement re-check (same contract as mcp_fetch).
        if (!$this->topicAllowsServer($userId, $context->classification, $serverId)) {
            return NodeResult::failed('this topic is not allowed to use MCP connections');
        }

        $catalogTool = $this->findTool($server, $tool);
        if (null === $catalogTool) {
            return NodeResult::failed(sprintf("the tool '%s' does not exist on this connection", $tool));
        }
        if ($this->isDestructiveTool($catalogTool['annotations'])) {
            return NodeResult::failed(sprintf("the tool '%s' declares itself destructive and is not allowed", $tool));
        }

        $arguments = $this->resolveArguments($node, $context);

        try {
            $result = $this->client->callTool($server, $tool, $arguments);
        } catch (McpClientException $e) {
            $this->logger->warning('McpActionRunner: tool call failed', [
                'server_id' => $serverId,
                'tool' => $tool,
                'error' => $e->getMessage(),
            ]);

            return NodeResult::failed('could not reach the connected system: '.$e->getMessage());
        }

        $text = $this->formatContent($result['content']);
        if ($result['isError']) {
            return NodeResult::failed('the connected system reported an error: '.mb_substr($text, 0, 300));
        }
        if ('' === trim($text)) {
            $text = sprintf("The action '%s' on %s completed.", $tool, $server->getName());
        }

        // Auditable trace of every external write (who/where/what).
        $this->logger->info('McpActionRunner: write action succeeded', [
            'user_id' => $userId,
            'server_id' => $serverId,
            'server' => $server->getName(),
            'tool' => $tool,
            'argument_keys' => array_keys($arguments),
        ]);

        return NodeResult::ok($text, [], [
            'mcp' => ['server_id' => $serverId, 'server' => $server->getName(), 'tool' => $tool, 'write' => true],
            'query' => $server->getName().' · '.$tool,
        ]);
    }

    /**
     * The write-tool sub-catalog: ONLY servers whose owner enabled writes, and
     * ONLY tools that declare themselves mutating (read tools stay under
     * `mcp_fetch`; destructive tools are excluded entirely).
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
            if (!$server->allowsWrite()) {
                continue;
            }
            $serverId = (int) $server->getId();
            if (null !== $allowlist && !in_array($serverId, $allowlist, true)) {
                continue;
            }

            $tools = [];
            foreach ($entry['tools'] as $tool) {
                if (!$this->isMutatingTool($tool['annotations']) || $this->isDestructiveTool($tool['annotations'])) {
                    continue;
                }
                $tools[] = $tool['name'].$this->renderArgumentHint($tool['inputSchema'])
                    .('' !== $tool['description'] ? ' — '.$this->oneLine($tool['description']) : '');
            }
            if ([] === $tools) {
                continue;
            }

            $lines[] = sprintf('    • server_id %d "%s" — write tools:', $serverId, $server->getName());
            foreach ($tools as $toolLine) {
                $lines[] = '      - '.$toolLine;
            }
        }

        if ([] === $lines) {
            return null;
        }

        return "  Write-enabled connections for this user:\n".implode("\n", $lines);
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
     * Mutating = the tool declares `readOnlyHint: false` (same reading as
     * {@see McpFetchRunner::isMutatingTool()}). Tools WITHOUT annotations stay
     * in the `mcp_fetch` catalog — this runner only advertises declared writes.
     *
     * @param array<string, mixed> $annotations
     */
    private function isMutatingTool(array $annotations): bool
    {
        return false === ($annotations['readOnlyHint'] ?? null)
            || true === ($annotations['destructiveHint'] ?? null);
    }

    /**
     * Destructive (deletes, irreversible updates) stays out of scope in v1 —
     * creating pages/tickets is reversible by the user, deleting them is not.
     *
     * @param array<string, mixed> $annotations
     */
    private function isDestructiveTool(array $annotations): bool
    {
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
