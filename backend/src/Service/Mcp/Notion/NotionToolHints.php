<?php

declare(strict_types=1);

namespace App\Service\Mcp\Notion;

use App\Entity\McpServerConfig;

/**
 * Classifies the tools of the hosted Notion server as read, write or
 * destructive.
 *
 * Notion ships new tools often and does not always annotate them. A missing
 * `readOnlyHint` would put a write on the read path, so this is an
 * allow-list: known tools get their class from the lists below, an unknown
 * tool stays a read only when Notion itself declares it read-only and not
 * destructive, and everything else counts as destructive. The connection's
 * permissions (McpToolPermissions) decide what each class may do; only the
 * tools that cannot work through a tool call are fixed to block.
 * Applies only to `mcp.notion.com`.
 */
final class NotionToolHints
{
    public const HOST = 'mcp.notion.com';

    /** @var list<string> */
    private const READ = [
        'search',
        'fetch',
        'connector-search',
        'get-file-download-urls',
        'get-comments',
        'get-users',
        'get-teams',
        'get-groups',
        'get-self',
        'get-tool-access',
        'get-async-task',
        'get-version-changes',
        'view-version',
        'get-suggested-edit',
        'query-data-sources',
        'query-multiple-data-sources',
        'query-meeting-notes',
        'list-recent-pages',
        'list-favorite-pages',
        'list-shared-pages',
        'list-private-pages',
        'list-page-backlinks',
        'calendar-list-calendars',
        'calendar-list-events',
        'calendar-list-coworkers-events',
        'calendar-list-calendar-resources',
        'calendar-list-contacts',
        'search-agents',
        'get-session-status',
        'wait-session',
        'query-sessions',
        'search-sessions',
        'list-session-events',
        'read-session-event',
        'get-enabled-skills',
        'download-skill',
        'memory-search',
    ];

    /** @var list<string> */
    private const WRITE = [
        'create-pages',
        'update-page',
        'create-comment',
        'update-comment',
        'set-comment-reaction',
        'set-discussion-resolved',
        'create-database',
        'update-data-source',
        'duplicate-page',
        'create-view',
        'update-view',
        'create-folder',
        'update-folder',
        'restore-pages',
        'create-suggested-edit',
        'resolve-suggested-edit',
        'calendar-create-events',
        'calendar-update-events',
        'spawn-session',
        'send-message-to-session',
        'upload-skill',
        'convert-page-to-skill',
        'update-enabled-skills',
    ];

    /** @var list<string> Delete, move to trash, cancel or overwrite. */
    private const DESTRUCTIVE = [
        'delete-pages',
        'delete-databases',
        'delete-comment',
        'move-pages',
        'restore-version',
        'calendar-cancel-events',
        'stop-session',
    ];

    /**
     * Cannot work through a tool call: they need a file transfer outside the
     * call. Always blocked and not changeable by the person.
     *
     * @var list<string>
     */
    private const UNUSABLE = [
        'create-file-upload',
        'create-attachment',
        'download-attachment',
    ];

    private const READ_HINTS = ['readOnlyHint' => true, 'destructiveHint' => false];
    private const WRITE_HINTS = ['readOnlyHint' => false, 'destructiveHint' => false];
    private const DESTRUCTIVE_HINTS = ['readOnlyHint' => false, 'destructiveHint' => true];

    /**
     * @param list<array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>}> $tools
     *
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>}>
     */
    public function apply(McpServerConfig $server, array $tools): array
    {
        if (!self::isNotion($server->getUrl())) {
            return $tools;
        }

        $annotated = [];
        foreach ($tools as $tool) {
            $annotated[] = $this->annotate($tool);
        }

        return $annotated;
    }

    public static function isNotion(string $url): bool
    {
        return self::HOST === strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    /**
     * A Notion tool that cannot work through a tool call. Never true for
     * another server.
     */
    public static function isUnusable(string $serverUrl, string $tool): bool
    {
        return self::isNotion($serverUrl) && in_array(self::bare($tool), self::UNUSABLE, true);
    }

    public static function bare(string $name): string
    {
        $name = strtolower($name);

        return str_starts_with($name, 'notion-') ? substr($name, strlen('notion-')) : $name;
    }

    /**
     * @param array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>} $tool
     *
     * @return array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>}
     */
    private function annotate(array $tool): array
    {
        $annotations = $tool['annotations'];
        $bare = self::bare((string) $tool['name']);

        // Notion marks update-page destructive for its replace_content mode.
        // The tool itself is a write; calls whose arguments delete content
        // are escalated to an approval by NotionWriteGuard.
        if (NotionPageWrite::UPDATE_TOOL === $bare) {
            return $this->with($tool, self::WRITE_HINTS);
        }
        if (in_array($bare, self::UNUSABLE, true) || in_array($bare, self::DESTRUCTIVE, true)) {
            return $this->with($tool, self::DESTRUCTIVE_HINTS);
        }

        $known = in_array($bare, self::READ, true) || in_array($bare, self::WRITE, true);
        $hinted = array_key_exists('readOnlyHint', $annotations) || array_key_exists('destructiveHint', $annotations);
        if ($known) {
            return $hinted ? $tool : $this->with($tool, in_array($bare, self::READ, true) ? self::READ_HINTS : self::WRITE_HINTS);
        }

        // An unknown tool stays a read only when Notion itself declares it
        // read-only and not destructive. Anything else counts as destructive.
        $declaredRead = true === ($annotations['readOnlyHint'] ?? null) && true !== ($annotations['destructiveHint'] ?? null);

        return $declaredRead ? $tool : $this->with($tool, self::DESTRUCTIVE_HINTS);
    }

    /**
     * @param array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>} $tool
     * @param array{readOnlyHint: bool, destructiveHint: bool}                                                               $hints
     *
     * @return array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>}
     */
    private function with(array $tool, array $hints): array
    {
        $tool['annotations'] = array_merge($tool['annotations'], $hints);

        return $tool;
    }
}
