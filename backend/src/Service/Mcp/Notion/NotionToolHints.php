<?php

declare(strict_types=1);

namespace App\Service\Mcp\Notion;

use App\Entity\McpServerConfig;

/**
 * Decides which tools of the hosted Notion server Synaplan may offer.
 *
 * Notion ships new tools often and does not always annotate them. A missing
 * `readOnlyHint` would put a write on the read path, so this is an
 * allow-list: known read tools read, known write tools need "The AI may
 * change Notion", and everything else is marked destructive, which hides it
 * from every planner catalog and refuses it at run time. Applies only to
 * `mcp.notion.com`.
 */
final class NotionToolHints
{
    public const HOST = 'mcp.notion.com';

    /** @var list<string> */
    private const READ = [
        'search',
        'fetch',
        'get-file-download-urls',
        'get-comments',
        'get-users',
        'get-teams',
        'get-groups',
        'get-self',
        'get-tool-access',
        'get-async-task',
        'query-data-sources',
        'query-multiple-data-sources',
        'query-meeting-notes',
        'list-recent-pages',
        'list-favorite-pages',
        'list-shared-pages',
        'list-private-pages',
        'list-page-backlinks',
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
        'restore-pages',
    ];

    /**
     * Never offered, even when Notion calls them harmless: they delete or
     * move content, need a file transfer outside the tool call, or belong to
     * Notion Calendar, Notion's own agents, skills and memory, which the
     * "The AI may change Notion" switch does not describe.
     *
     * @var list<string>
     */
    private const BLOCKED = [
        'delete-pages',
        'delete-databases',
        'delete-comment',
        'move-pages',
        'update-folder',
        'create-file-upload',
        'create-attachment',
        'download-attachment',
        'calendar-list-calendars',
        'calendar-list-events',
        'calendar-list-coworkers-events',
        'calendar-list-calendar-resources',
        'calendar-list-contacts',
        'calendar-create-events',
        'calendar-update-events',
        'calendar-cancel-events',
        'search-agents',
        'spawn-session',
        'send-message-to-session',
        'stop-session',
        'wait-session',
        'get-session-status',
        'query-sessions',
        'search-sessions',
        'list-session-events',
        'read-session-event',
        'get-enabled-skills',
        'download-skill',
        'upload-skill',
        'convert-page-to-skill',
        'update-enabled-skills',
        'memory-search',
        'show-advanced-analysis-next-steps',
    ];

    private const READ_HINTS = ['readOnlyHint' => true, 'destructiveHint' => false];
    private const WRITE_HINTS = ['readOnlyHint' => false, 'destructiveHint' => false];
    private const BLOCKED_HINTS = ['readOnlyHint' => false, 'destructiveHint' => true];

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
        // NotionWriteGuard refuses that mode, so the tool only edits here.
        if (NotionPageWrite::UPDATE_TOOL === $bare) {
            return $this->with($tool, self::WRITE_HINTS);
        }
        if (in_array($bare, self::BLOCKED, true)) {
            return $this->with($tool, self::BLOCKED_HINTS);
        }

        $known = in_array($bare, self::READ, true) || in_array($bare, self::WRITE, true);
        $hinted = array_key_exists('readOnlyHint', $annotations) || array_key_exists('destructiveHint', $annotations);
        if ($known) {
            return $hinted ? $tool : $this->with($tool, in_array($bare, self::READ, true) ? self::READ_HINTS : self::WRITE_HINTS);
        }

        $declaredRead = true === ($annotations['readOnlyHint'] ?? null) && true !== ($annotations['destructiveHint'] ?? null);

        return $declaredRead ? $tool : $this->with($tool, self::BLOCKED_HINTS);
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
