<?php

declare(strict_types=1);

namespace App\Service\Multitask;

use App\AI\Provider\ConnectedSystemSortVote;
use App\Entity\McpServerConfig;
use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\McpClientConfig;
use App\Service\Message\Processing\PromptToolFlag;
use App\Service\Prompt\PromptService;
use App\Service\Runtime\RuntimeProfile;
use Psr\Log\LoggerInterface;

/**
 * Whether a message names one of the user's enabled MCP connections.
 *
 * A single-step sorter vote skips the planner, and only the planner can call
 * a connection. When the message names a connection the topic is allowed to
 * use, that vote is ignored and the turn is planned. The same gate as
 * {@see \App\Service\Message\ConnectedSystemsHint}: MCP client on, mcp_fetch
 * on, server enabled. No network call — a disconnected OAuth token still
 * counts, and the runner reports that failure itself.
 */
final readonly class ConnectedSystemMention
{
    private const MAX_NAMES = 8;

    public function __construct(
        private McpServerConfigRepository $servers,
        private McpClientConfig $clientConfig,
        private MultitaskRoutingConfig $routingConfig,
        private PromptService $prompts,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Display names for the chat prompt. Empty when nothing is connected.
     *
     * @return list<string>
     */
    public function names(?int $userId): array
    {
        $names = [];
        foreach ($this->enabledServers($userId) as $server) {
            $name = ConnectedSystemSortVote::singleLine($server->getName(), 60);
            if (mb_strlen($name) < 3) {
                continue;
            }
            $names[] = $name;
            if (count($names) >= self::MAX_NAMES) {
                break;
            }
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $classification
     */
    public function mentioned(?int $userId, string $text, array $classification): bool
    {
        $servers = $this->enabledServers($userId);
        if ([] === $servers) {
            return false;
        }

        [$allowed, $allowlist] = $this->topicGate($userId, $classification);
        if (!$allowed) {
            return false;
        }

        foreach ($servers as $server) {
            $serverId = (int) $server->getId();
            if (null !== $allowlist && !in_array($serverId, $allowlist, true)) {
                continue;
            }
            if (!ConnectedSystemSortVote::containsWord($text, $server->getName())) {
                continue;
            }

            $this->logger->info('ConnectedSystemMention: message names a connected system, planning anyway', [
                'user_id' => $userId,
                'server_id' => $serverId,
                'server_name' => $server->getName(),
            ]);

            return true;
        }

        return false;
    }

    /**
     * @return list<McpServerConfig>
     */
    private function enabledServers(?int $userId): array
    {
        if (null === $userId || $userId <= 0) {
            return [];
        }
        if (!$this->clientConfig->isClientEnabled($userId)
            || !$this->routingConfig->isFeatureEnabled(MultitaskRoutingConfig::KEY_MCP_FETCH_ENABLED, $userId, false)) {
            return [];
        }

        try {
            return $this->servers->findEnabledByUser($userId);
        } catch (\Throwable $e) {
            $this->logger->warning('ConnectedSystemMention: lookup failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param array<string, mixed> $classification
     *
     * @return array{0: bool, 1: list<int>|null}
     */
    private function topicGate(?int $userId, array $classification): array
    {
        $metadata = $this->metadata($userId, $classification);
        if (true !== PromptToolFlag::read($metadata, 'tool_mcp')) {
            return [false, null];
        }

        return [true, $this->allowlist($metadata)];
    }

    /**
     * @param array<string, mixed> $classification
     *
     * @return array<string, mixed>
     */
    private function metadata(?int $userId, array $classification): array
    {
        $profile = $classification['runtime_profile'] ?? null;
        if ($profile instanceof RuntimeProfile && array_key_exists('tool_mcp', $profile->toolFlags)) {
            $metadata = ['tool_mcp' => $profile->toolFlags['tool_mcp']];
            if (array_key_exists('mcp_servers', $profile->toolFlags)) {
                $metadata['mcp_servers'] = $profile->toolFlags['mcp_servers'];
            }

            return $metadata;
        }

        $topic = $classification['topic'] ?? null;
        if (!is_string($topic) || '' === $topic || null === $userId || $userId <= 0) {
            return [];
        }

        try {
            $prompt = $this->prompts->getPromptWithMetadata($topic, $userId);
        } catch (\Throwable $e) {
            $this->logger->warning('ConnectedSystemMention: topic lookup failed', [
                'user_id' => $userId,
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $metadata = is_array($prompt['metadata'] ?? null) ? $prompt['metadata'] : [];

        return $metadata;
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return list<int>|null
     */
    private function allowlist(array $metadata): ?array
    {
        $raw = $metadata['mcp_servers'] ?? null;
        $parts = [];
        if (is_array($raw)) {
            $parts = $raw;
        } elseif (is_string($raw) && '' !== trim($raw)) {
            $parts = explode(',', $raw);
        } else {
            return null;
        }

        $ids = [];
        foreach ($parts as $part) {
            if (is_string($part)) {
                $part = trim($part);
            }
            if (is_numeric($part) && (int) $part > 0) {
                $ids[] = (int) $part;
            }
        }

        return [] === $ids ? null : $ids;
    }
}
