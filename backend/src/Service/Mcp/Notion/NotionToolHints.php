<?php

declare(strict_types=1);

namespace App\Service\Mcp\Notion;

use App\Entity\McpServerConfig;

/**
 * Fills missing MCP tool annotations for the hosted Notion server.
 *
 * Notion's write tools sometimes arrive without `readOnlyHint`. The fetch
 * runner treats a missing hint as read-only, so a create/update would run on
 * the read path. Hints are applied only when the server sent neither
 * `readOnlyHint` nor `destructiveHint`, and only for `mcp.notion.com`.
 */
final class NotionToolHints
{
    public const HOST = 'mcp.notion.com';

    /** @var list<string> */
    private const READ = [
        'search',
        'fetch',
        'get-comments',
        'get-users',
        'get-teams',
        'query-data-sources',
    ];

    /** @var list<string> */
    private const WRITE = [
        'create-pages',
        'update-page',
        'create-comment',
        'create-database',
        'update-data-source',
        'duplicate-page',
        'create-view',
        'update-view',
    ];

    /** @var list<string> */
    private const DESTRUCTIVE = [
        'delete-pages',
        'move-pages',
    ];

    /**
     * @param list<array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>}> $tools
     *
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>}>
     */
    public function apply(McpServerConfig $server, array $tools): array
    {
        if (self::HOST !== $this->host($server->getUrl())) {
            return $tools;
        }

        $annotated = [];
        foreach ($tools as $tool) {
            $annotated[] = $this->annotate($tool);
        }

        return $annotated;
    }

    /**
     * @param array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>} $tool
     *
     * @return array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>}
     */
    private function annotate(array $tool): array
    {
        $annotations = $tool['annotations'];
        if (array_key_exists('readOnlyHint', $annotations) || array_key_exists('destructiveHint', $annotations)) {
            return $tool;
        }

        $hints = $this->hintsFor($this->bare((string) $tool['name']));
        if (null === $hints) {
            return $tool;
        }

        $tool['annotations'] = array_merge($annotations, $hints);

        return $tool;
    }

    /**
     * @return array{readOnlyHint: bool, destructiveHint: bool}|null
     */
    private function hintsFor(string $bare): ?array
    {
        if (in_array($bare, self::READ, true)) {
            return ['readOnlyHint' => true, 'destructiveHint' => false];
        }
        if (in_array($bare, self::DESTRUCTIVE, true)) {
            return ['readOnlyHint' => false, 'destructiveHint' => true];
        }
        if (in_array($bare, self::WRITE, true)) {
            return ['readOnlyHint' => false, 'destructiveHint' => false];
        }

        return null;
    }

    private function bare(string $name): string
    {
        $name = strtolower($name);

        return str_starts_with($name, 'notion-') ? substr($name, strlen('notion-')) : $name;
    }

    private function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }
}
