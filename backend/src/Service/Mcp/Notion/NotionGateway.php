<?php

declare(strict_types=1);

namespace App\Service\Mcp\Notion;

use App\Entity\McpServerConfig;
use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\McpClient;
use App\Service\Mcp\McpClientException;
use App\Service\Mcp\McpOAuthState;
use App\Service\Mcp\McpToolRegistry;
use App\Service\Security\EncryptionService;

/**
 * Finds the Notion connection a person may use and calls one Notion tool on
 * it outside a chat plan (file upload, saved-task delivery, watches).
 *
 * A person's own connection always wins. Without one, an organisation-wide
 * connection shared by an admin can be read, never written.
 */
final readonly class NotionGateway
{
    private const PREFIX = 'notion-';

    public function __construct(
        private McpServerConfigRepository $servers,
        private EncryptionService $encryption,
        private McpClient $client,
        private McpToolRegistry $tools,
        private NotionSharing $sharing,
    ) {
    }

    /**
     * The person's own Notion row, connected or not; a connected one wins.
     */
    public function find(int $userId): ?McpServerConfig
    {
        $fallback = null;
        foreach ($this->servers->findByUser($userId) as $server) {
            if (!NotionToolHints::isNotion($server->getUrl())) {
                continue;
            }
            $fallback ??= $server;
            if ($this->isConnected($server)) {
                return $server;
            }
        }

        return $fallback;
    }

    /**
     * The person's own connected Notion, the only one Synaplan writes with.
     */
    public function writable(int $userId): ?McpServerConfig
    {
        $server = $this->find($userId);

        return null !== $server && $server->isEnabled() && $this->isConnected($server) ? $server : null;
    }

    /**
     * Own connection first, else the organisation's shared one.
     */
    public function readable(int $userId): ?McpServerConfig
    {
        return $this->writable($userId) ?? $this->sharing->sharedServer($userId);
    }

    public function isConnected(McpServerConfig $server): bool
    {
        return $server->isOAuth()
            && McpOAuthState::STATUS_CONNECTED === $server->getDecryptedOAuthState($this->encryption)->status;
    }

    /**
     * Calls one Notion tool by its bare name and decodes the JSON answer.
     * A tool that reports an error throws with Notion's own message.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     *
     * @throws McpClientException
     */
    public function call(McpServerConfig $server, string $bareTool, array $arguments): array
    {
        $result = $this->client->callTool($server, $this->toolName($server, $bareTool), $arguments);
        $text = '';
        foreach ($result['content'] as $block) {
            if ('text' === ($block['type'] ?? null) && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }
        $text = trim($text);
        if ($result['isError']) {
            throw new McpClientException('' !== $text ? mb_substr($text, 0, 300) : 'Notion reported an error and gave no details.');
        }
        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : ['text' => $text];
    }

    /**
     * Dashed page id from a Notion link or a bare id, or null.
     */
    public static function pageId(string $input): ?string
    {
        $input = trim($input);
        if ('' === $input) {
            return null;
        }
        if (1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $input)) {
            return strtolower($input);
        }
        if (str_starts_with(strtolower($input), 'http')) {
            $host = strtolower((string) parse_url($input, PHP_URL_HOST));
            if (!self::isNotionHost($host)) {
                return null;
            }
            $input = (string) parse_url($input, PHP_URL_PATH);
        }
        if (!preg_match_all('/[0-9a-f]{32}/i', $input, $matches)) {
            return null;
        }
        $hex = strtolower($matches[0][count($matches[0]) - 1]);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }

    public static function pageUrl(string $pageId): string
    {
        return 'https://www.notion.so/'.str_replace('-', '', $pageId);
    }

    public static function isNotionHost(string $host): bool
    {
        foreach (['notion.so', 'notion.com', 'notion.site'] as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    private function toolName(McpServerConfig $server, string $bareTool): string
    {
        foreach ($this->tools->toolsFor($server) as $tool) {
            if (NotionToolHints::bare($tool['name']) === $bareTool) {
                return $tool['name'];
            }
        }

        return self::PREFIX.$bareTool;
    }
}
