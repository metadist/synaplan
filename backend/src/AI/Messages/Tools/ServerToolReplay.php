<?php

declare(strict_types=1);

namespace App\AI\Messages\Tools;

/**
 * Anthropic server-tool replay: a `web_fetch` / `web_search` use must travel
 * with its matching `*_tool_result`, or both sides must be dropped.
 *
 * The desktop used to strip result blocks because Anthropic once rejected
 * them. Current Anthropic does the opposite: an unpaired `srvtoolu_*` use
 * fails the next `/v1/messages` call.
 */
final class ServerToolReplay
{
    private const RESULT_TYPE = [
        'web_search' => 'web_search_tool_result',
        'web_fetch' => 'web_fetch_tool_result',
    ];

    /**
     * @param array<string, mixed> $block
     */
    public static function isServerToolUse(array $block): bool
    {
        $type = (string) ($block['type'] ?? '');
        if ('server_tool_use' === $type) {
            return true;
        }
        if ('tool_use' !== $type) {
            return false;
        }
        $name = (string) ($block['name'] ?? '');
        $id = (string) ($block['id'] ?? '');
        // Synaplan's catalog `web_search` is a normal tool_use + tool_result.
        // Anthropic page fetch, and any srvtoolu_* use, need *_tool_result.
        if (AnthropicServerTools::WEB_FETCH_NAME === $name) {
            return true;
        }

        return AnthropicServerTools::WEB_SEARCH_NAME === $name && str_starts_with($id, 'srvtoolu_');
    }

    /**
     * @param array<string, mixed> $block
     */
    public static function isServerToolResult(array $block): bool
    {
        $type = (string) ($block['type'] ?? '');

        return \in_array($type, self::RESULT_TYPE, true);
    }

    public static function resultTypeFor(string $name): ?string
    {
        return self::RESULT_TYPE[$name] ?? null;
    }

    /**
     * Keep server-tool use/result as a pair; drop unpaired uses and orphan
     * results. Text, thinking, and client `tool_use` pass through.
     *
     * @param list<mixed> $content
     *
     * @return list<mixed>
     */
    public static function sanitizeAssistantContent(array $content): array
    {
        if (!self::isBlockList($content)) {
            return $content;
        }

        $paired = self::pairedUseIds($content);
        $out = [];
        foreach ($content as $block) {
            if (!\is_array($block)) {
                continue;
            }
            if (self::isServerToolUse($block)) {
                $id = (string) ($block['id'] ?? '');
                if ('' !== $id && isset($paired[$id])) {
                    $out[] = $block;
                }
                continue;
            }
            if (self::isServerToolResult($block)) {
                $id = (string) ($block['tool_use_id'] ?? '');
                if ('' !== $id && isset($paired[$id])) {
                    $out[] = $block;
                }
                continue;
            }
            $out[] = $block;
        }

        return $out;
    }

    /**
     * @param list<mixed> $messages
     *
     * @return list<mixed>
     */
    public static function sanitizeMessages(array $messages): array
    {
        $droppedIds = [];
        foreach ($messages as $i => $message) {
            if (!\is_array($message) || 'assistant' !== ($message['role'] ?? '')) {
                continue;
            }
            $content = $message['content'] ?? null;
            if (!\is_array($content) || !self::isBlockList($content)) {
                continue;
            }
            /** @var list<array<string, mixed>> $content */
            $before = self::serverUseIds($content);
            $clean = self::sanitizeAssistantContent($content);
            $after = self::pairedUseIds($clean);
            foreach ($before as $id) {
                if (!isset($after[$id])) {
                    $droppedIds[$id] = true;
                }
            }
            $messages[$i]['content'] = $clean;
        }

        if ([] === $droppedIds) {
            return $messages;
        }

        foreach ($messages as $i => $message) {
            if (!\is_array($message) || 'user' !== ($message['role'] ?? '')) {
                continue;
            }
            $content = $message['content'] ?? null;
            if (!\is_array($content) || !self::isBlockList($content)) {
                continue;
            }
            $kept = [];
            foreach ($content as $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $type = (string) ($block['type'] ?? '');
                $id = (string) ($block['tool_use_id'] ?? '');
                if (
                    '' !== $id
                    && isset($droppedIds[$id])
                    && ('tool_result' === $type || self::isServerToolResult($block))
                ) {
                    continue;
                }
                $kept[] = $block;
            }
            $messages[$i]['content'] = $kept;
        }

        return $messages;
    }

    /**
     * @param list<mixed> $content
     *
     * @return list<string>
     */
    public static function unpairedUseIds(array $content): array
    {
        if (!self::isBlockList($content)) {
            return [];
        }
        $paired = self::pairedUseIds($content);
        $out = [];
        foreach (self::serverUseIds($content) as $id) {
            if (!isset($paired[$id])) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * @param list<mixed> $content
     */
    public static function isBlockList(array $content): bool
    {
        if ([] === $content) {
            return true;
        }
        $first = $content[array_key_first($content)] ?? null;

        return \is_array($first) && isset($first['type']);
    }

    /**
     * @param list<mixed> $content
     *
     * @return array<string, true>
     */
    private static function pairedUseIds(array $content): array
    {
        $resultsById = [];
        foreach ($content as $block) {
            if (!\is_array($block) || !self::isServerToolResult($block)) {
                continue;
            }
            $id = (string) ($block['tool_use_id'] ?? '');
            $type = (string) ($block['type'] ?? '');
            if ('' !== $id) {
                $resultsById[$id] = $type;
            }
        }

        $paired = [];
        foreach ($content as $block) {
            if (!\is_array($block) || !self::isServerToolUse($block)) {
                continue;
            }
            $id = (string) ($block['id'] ?? '');
            $name = (string) ($block['name'] ?? '');
            $expected = self::resultTypeFor($name);
            if ('' === $id || null === $expected) {
                continue;
            }
            if (($resultsById[$id] ?? null) === $expected) {
                $paired[$id] = true;
            }
        }

        return $paired;
    }

    /**
     * @param list<mixed> $content
     *
     * @return list<string>
     */
    private static function serverUseIds(array $content): array
    {
        $ids = [];
        foreach ($content as $block) {
            if (!\is_array($block) || !self::isServerToolUse($block)) {
                continue;
            }
            $id = (string) ($block['id'] ?? '');
            if ('' !== $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
