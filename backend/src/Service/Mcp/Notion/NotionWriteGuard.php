<?php

declare(strict_types=1);

namespace App\Service\Mcp\Notion;

/**
 * Argument rules for Notion write tools, checked right before a call leaves.
 *
 * The allowed tools can still remove content through single arguments
 * (replacing a page, trashing a data source, dropping a column). Those
 * calls are refused. Background execution is switched off, because nothing
 * polls Notion's async task afterwards and the answer would report a page
 * that does not exist yet.
 */
final class NotionWriteGuard
{
    /** Commands of update-page that never remove page content. */
    private const SAFE_PAGE_COMMANDS = ['update_properties', 'update_content', 'insert_content'];

    private const DATA_SOURCE_TOOL = 'update-data-source';

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public static function normalize(string $serverUrl, string $toolName, array $arguments): array
    {
        if (NotionToolHints::isNotion($serverUrl)) {
            unset($arguments['allow_async']);
        }

        return $arguments;
    }

    /**
     * Why a Notion call must not run, or null when it may.
     *
     * @param array<string, mixed> $arguments
     */
    public static function refusal(string $serverUrl, string $toolName, array $arguments): ?string
    {
        if (!NotionToolHints::isNotion($serverUrl)) {
            return null;
        }

        $bare = NotionToolHints::bare($toolName);
        if (true === ($arguments['allow_deleting_content'] ?? false)) {
            return sprintf('Notion %s was asked to delete page content. Nothing was sent and nothing was changed in Notion.', $bare);
        }
        if (NotionPageWrite::UPDATE_TOOL === $bare) {
            return self::pageRefusal($arguments);
        }
        if (self::DATA_SOURCE_TOOL === $bare) {
            return self::dataSourceRefusal($arguments);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function pageRefusal(array $arguments): ?string
    {
        $command = $arguments['command'] ?? null;
        if (is_string($command) && in_array($command, self::SAFE_PAGE_COMMANDS, true)) {
            return null;
        }

        return sprintf(
            "Notion update-page only changes properties, edits text or adds text here. The command '%s' could remove page content, so nothing was sent and nothing was changed in Notion.",
            is_string($command) ? $command : '',
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function dataSourceRefusal(array $arguments): ?string
    {
        if (true === ($arguments['in_trash'] ?? false)) {
            return 'Moving a Notion database to the trash is not allowed here. Nothing was sent and nothing was changed in Notion.';
        }
        $statements = $arguments['statements'] ?? null;
        if (is_string($statements) && 1 === preg_match('/\bDROP\s+COLUMN\b/i', $statements)) {
            return 'Removing a database column would delete its values, so it is not allowed here. Nothing was sent and nothing was changed in Notion.';
        }

        return null;
    }
}
