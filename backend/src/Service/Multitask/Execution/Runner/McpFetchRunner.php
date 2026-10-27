<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Entity\McpServerConfig;
use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\AtlassianToolArguments;
use App\Service\Mcp\McpArgumentSchema;
use App\Service\Mcp\McpClient;
use App\Service\Mcp\McpClientConfig;
use App\Service\Mcp\McpClientException;
use App\Service\Mcp\McpToolRegistry;
use App\Service\Mcp\Notion\NotionFetchArguments;
use App\Service\Mcp\Notion\NotionImageAttach;
use App\Service\Mcp\Notion\NotionImagePlan;
use App\Service\Mcp\Notion\NotionToolHints;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Multitask\Skill\SkillDescriptor;
use App\Service\Prompt\PromptService;
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
        private AtlassianToolArguments $atlassianArguments,
        private NotionFetchArguments $notionFetch,
        private NotionImageAttach $images,
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
                available: null,
                planAdjuster: fn (array $payload, string $messageText, ?int $userId, ?array $allowed, string $topic): array => $this->adjustNotionImagePlan($payload, $messageText, $userId, $allowed, $topic),
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

        $sourceText = (string) $context->message->getText();
        $arguments = McpArgumentSchema::clean(
            $this->notionFetch->complete(
                $server,
                $catalogTool,
                $this->atlassianArguments->complete($server, $catalogTool, $this->resolveArguments($node, $context), $sourceText),
                $sourceText,
            ),
            $catalogTool['inputSchema'],
        );

        if ($this->images->applies($server->getUrl(), $tool, $arguments)) {
            if ($userId <= 0) {
                return NodeResult::failed('this data connection is not available');
            }

            return $this->images->attach($server, $tool, $arguments, $this->language($context));
        }

        try {
            $result = $this->client->callTool($server, $tool, $arguments);
        } catch (McpClientException $e) {
            return $this->requestFailed($serverId, $server->getName(), $tool, $e->getMessage(), $e->httpStatus);
        }

        $text = $this->formatContent($result['content']);
        if ($result['isError']) {
            return $this->reportedError($serverId, $server->getName(), $tool, $text);
        }
        if ('' === trim($text)) {
            return $this->failReported(
                'McpFetchRunner: tool returned no content',
                $serverId,
                $tool,
                sprintf('%s returned no content.', $server->getName()),
                $this->callMetadata($serverId, $server->getName(), $tool),
            );
        }

        $this->logger->info('McpFetchRunner: tool call succeeded', [
            'server_id' => $serverId,
            'tool' => $tool,
            'chars' => mb_strlen($text),
        ]);

        return NodeResult::ok($text, [], $this->callMetadata($serverId, $server->getName(), $tool));
    }

    /**
     * The tool answered with isError. Answer steps still run; the card stays failed.
     */
    private function reportedError(int $serverId, string $serverName, string $tool, string $detail): NodeResult
    {
        return $this->failReported(
            'McpFetchRunner: tool reported an error',
            $serverId,
            $tool,
            $this->reportedSentence($serverName, $detail),
            $this->callMetadata($serverId, $serverName, $tool),
        );
    }

    /**
     * The call never produced a tool result (blocked URL, auth, HTTP error,
     * unreadable body). That is not the server reporting a tool error.
     */
    private function requestFailed(int $serverId, string $serverName, string $tool, string $detail, ?int $httpStatus = null): NodeResult
    {
        $detail = trim($detail);
        $error = '' === $detail
            ? sprintf('%s could not be reached.', $serverName)
            : sprintf('%s could not be reached: %s', $serverName, mb_substr($detail, 0, 300));

        return $this->failReported(
            'McpFetchRunner: tool request failed',
            $serverId,
            $tool,
            $error,
            $this->callMetadata($serverId, $serverName, $tool),
            $httpStatus,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function failReported(string $logMessage, int $serverId, string $tool, string $error, array $metadata, ?int $httpStatus = null): NodeResult
    {
        $context = [
            'server_id' => $serverId,
            'tool' => $tool,
            'error' => $error,
        ];
        if (null !== $httpStatus) {
            $context['status'] = $httpStatus;
        }
        $this->logger->warning($logMessage, $context);

        return NodeResult::reportableFailure($error, $metadata);
    }

    private function reportedSentence(string $serverName, string $detail): string
    {
        $detail = trim($detail);

        return '' === $detail
            ? sprintf('%s reported an error and gave no details.', $serverName)
            : sprintf('%s reported an error: %s', $serverName, mb_substr($detail, 0, 300));
    }

    /**
     * @return array{mcp: array{server_id: int, server: string, tool: string}, query: string}
     */
    private function callMetadata(int $serverId, string $serverName, string $tool): array
    {
        return [
            'mcp' => ['server_id' => $serverId, 'server' => $serverName, 'tool' => $tool],
            'query' => $serverName.' · '.$tool,
        ];
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
                    .$this->notionFetch->argumentHint($server->getUrl(), $tool['name'])
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
     * An image the person wants from Notion still needs a fresh search. A
     * chat-only plan, including the fallback after an earlier Notion turn,
     * never reaches the connection.
     *
     * @param array<string, mixed> $payload
     * @param list<string>|null    $allowed
     *
     * @return array<string, mixed>
     */
    private function adjustNotionImagePlan(array $payload, string $messageText, ?int $userId, ?array $allowed, string $topic): array
    {
        if (!(new \ReflectionProperty(self::class, 'clientConfig'))->isInitialized($this)) {
            return $payload;
        }
        if (null !== $allowed && (!in_array(Capability::McpFetch->value, $allowed, true) || !in_array(Capability::ComposeReply->value, $allowed, true))) {
            return $payload;
        }
        if (null === $userId || $userId <= 0
            || !$this->clientConfig->isClientEnabled($userId)
            || !$this->routingConfig->isFeatureEnabled(MultitaskRoutingConfig::KEY_MCP_FETCH_ENABLED, $userId, false)) {
            return $payload;
        }
        // The stream writes CHAT before classification. That is not a prompt
        // topic, so it must not hide a connection the person named.
        if (self::isPlaceholderTopic($topic)) {
            $topic = 'general';
        }

        $server = null;
        foreach ($this->servers->findEnabledByUser($userId) as $candidate) {
            if (NotionToolHints::HOST !== strtolower((string) parse_url($candidate->getUrl(), PHP_URL_HOST))) {
                continue;
            }
            if (!$this->topicAllowsServer($userId, ['topic' => $topic], (int) $candidate->getId())) {
                continue;
            }
            $server = $candidate;
            break;
        }
        if (null === $server || !NotionImagePlan::mentions($messageText, $server->getName())) {
            return $payload;
        }

        $search = null;
        $urls = null;
        foreach ($this->toolRegistry->toolsFor($server) as $tool) {
            $bare = strtolower($tool['name']);
            $bare = str_starts_with($bare, 'notion-') ? substr($bare, strlen('notion-')) : $bare;
            if ('search' === $bare && null === $search) {
                $search = $tool['name'];
            }
            if (NotionImagePlan::URLS_TOOL === $bare && null === $urls) {
                $urls = $tool['name'];
            }
        }
        if (null === $search || null === $urls) {
            return $payload;
        }

        return NotionImagePlan::install($payload, $messageText, (int) $server->getId(), $search, $urls, $server->getName(), $allowed);
    }

    /**
     * Topics written before classification. `unknown` in any case; the others
     * only as written, so a user's prompt named `chat` keeps its topic.
     */
    private static function isPlaceholderTopic(string $topic): bool
    {
        return '' === $topic || 'unknown' === strtolower($topic) || in_array($topic, ['CHAT', 'CONTINUE'], true);
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
        $properties = is_array($inputSchema['properties'] ?? null) ? $inputSchema['properties'] : [];
        if ([] === $properties) {
            return '';
        }

        $required = [];
        foreach (is_array($inputSchema['required'] ?? null) ? $inputSchema['required'] : [] as $name) {
            if (is_string($name)) {
                $required[$name] = true;
            }
        }
        $must = [];
        $optional = [];
        foreach ($properties as $name => $schema) {
            if (!is_string($name)) {
                continue;
            }
            if (isset($required[$name])) {
                $must[] = $name;
            } else {
                $optional[] = $name;
            }
        }
        $parts = [];
        if ([] !== $must) {
            $parts[] = implode(', ', array_slice($must, 0, 6));
        }
        if ([] !== $optional) {
            $parts[] = 'optional: '.implode(', ', array_slice($optional, 0, 6));
        }

        return '('.implode('; ', $parts).')';
    }

    private function language(NodeContext $context): string
    {
        $classified = $context->classification['language'] ?? null;
        if (is_string($classified) && '' !== $classified) {
            return $classified;
        }
        $stored = $context->message->getLanguage();

        return '' !== $stored ? $stored : 'en';
    }

    private function oneLine(string $text): string
    {
        $line = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($line) > 140 ? mb_substr($line, 0, 137).'…' : $line;
    }
}
