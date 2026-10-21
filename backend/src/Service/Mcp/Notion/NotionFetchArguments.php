<?php

declare(strict_types=1);

namespace App\Service\Mcp\Notion;

use App\Entity\McpServerConfig;
use Psr\Log\LoggerInterface;

/**
 * Notion's fetch tool accepts one page id, database id, or URL. The planner
 * often pastes the whole search result into `id`, which Notion rejects with
 * "Supplied identifier must be a Notion page ID". Pick the page the person
 * asked for, or the first result, and send only that id.
 */
final readonly class NotionFetchArguments
{
    private const MIN_TITLE_CHARS = 3;

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Extra planner line for the Notion fetch tool. Empty for every other tool.
     */
    public function argumentHint(string $serverUrl, string $toolName): string
    {
        if (!$this->isFetch($serverUrl, $toolName)) {
            return '';
        }

        return ' — id is one page id or URL from a search result, never the whole search output';
    }

    /**
     * @param array{name: string, inputSchema?: array<string, mixed>} $tool
     * @param array<string, mixed>                                    $arguments
     *
     * @return array<string, mixed>
     */
    public function complete(McpServerConfig $server, array $tool, array $arguments, string $sourceText): array
    {
        if (!$this->isFetch($server->getUrl(), (string) $tool['name'])) {
            return $arguments;
        }

        $current = $arguments['id'] ?? null;
        if (is_string($current) && $this->isIdentifier(trim($current))) {
            return $arguments;
        }

        $picked = $this->pageIdFrom($current, $sourceText);
        if (null === $picked) {
            return $arguments;
        }

        $arguments['id'] = $picked;
        $this->logger->info('NotionFetchArguments: replaced a search payload with one page id', [
            'server_id' => $server->getId(),
            'tool' => $tool['name'],
        ]);

        return $arguments;
    }

    /**
     * One page id from a value the planner already sent: a real id, or a
     * search payload whose title matches the person's question.
     */
    public function pageIdFrom(mixed $current, string $sourceText): ?string
    {
        if (is_string($current) && $this->isIdentifier(trim($current))) {
            return trim($current);
        }

        return $this->pick($this->payload($current), $sourceText);
    }

    private function isFetch(string $serverUrl, string $toolName): bool
    {
        if (NotionToolHints::HOST !== strtolower((string) parse_url($serverUrl, PHP_URL_HOST))) {
            return false;
        }

        $name = strtolower($toolName);

        return 'fetch' === (str_starts_with($name, 'notion-') ? substr($name, strlen('notion-')) : $name);
    }

    private function payload(mixed $id): ?array
    {
        if (is_array($id)) {
            return $id;
        }
        if (!is_string($id)) {
            return null;
        }

        $trimmed = trim($id);
        if ('' === $trimmed || ('{' !== $trimmed[0] && '[' !== $trimmed[0])) {
            return null;
        }

        $decoded = json_decode($trimmed, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<mixed>|null $payload
     */
    private function pick(?array $payload, string $sourceText): ?string
    {
        if (null === $payload) {
            return null;
        }

        $rows = is_array($payload['results'] ?? null) ? $payload['results'] : (array_is_list($payload) ? $payload : []);
        $bestId = null;
        $bestLength = 0;
        $first = null;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $identifier = $this->identifier($row);
            if (null === $identifier) {
                continue;
            }
            $first ??= $identifier;
            $title = is_string($row['title'] ?? null) ? trim($row['title']) : '';
            if (mb_strlen($title) < self::MIN_TITLE_CHARS || !str_contains(mb_strtolower($sourceText), mb_strtolower($title))) {
                continue;
            }
            if (mb_strlen($title) > $bestLength) {
                $bestId = $identifier;
                $bestLength = mb_strlen($title);
            }
        }

        return $bestId ?? $first;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function identifier(array $row): ?string
    {
        $id = is_string($row['id'] ?? null) ? trim($row['id']) : '';
        if ($this->isIdentifier($id)) {
            return $id;
        }

        $url = is_string($row['url'] ?? null) ? trim($row['url']) : '';

        return $this->isIdentifier($url) ? $url : null;
    }

    private function isIdentifier(string $value): bool
    {
        if (1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            return true;
        }
        if (1 === preg_match('/^[0-9a-f]{32}$/i', $value)) {
            return true;
        }
        if (!str_starts_with(strtolower($value), 'https://')) {
            return false;
        }

        $host = strtolower((string) parse_url($value, PHP_URL_HOST));

        return 'notion.so' === $host
            || 'notion.com' === $host
            || 'notion.site' === $host
            || 'app.notion.com' === $host
            || str_ends_with($host, '.notion.so')
            || str_ends_with($host, '.notion.com')
            || str_ends_with($host, '.notion.site');
    }
}
