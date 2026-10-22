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
        // Synaplan's own `web_search` / `web_fetch` use a normal id and a
        // normal tool_result. Only Anthropic's srvtoolu_* pair needs the
        // server-tool result type; dropping those normal calls would hide
        // the page Synaplan just read.
        if (!str_starts_with($id, 'srvtoolu_')) {
            return false;
        }

        return AnthropicServerTools::WEB_FETCH_NAME === $name
            || AnthropicServerTools::WEB_SEARCH_NAME === $name;
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

    /**
     * Drop an Anthropic server-tool use that has no matching result, so a
     * client that also asked for `write_file` never sees an unpaired fetch.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public static function dropUnpairedServerUses(array $body): array
    {
        $content = $body['content'] ?? null;
        if (!\is_array($content)) {
            return $body;
        }

        $body['content'] = self::sanitizeAssistantContent($content);

        return $body;
    }

    /**
     * Keep Synaplan's tool uses in the history we send upstream, and leave
     * the client's own uses (`write_file`) out until the client runs them.
     *
     * @param list<mixed>                $content
     * @param list<array<string, mixed>> $clientUses
     *
     * @return list<mixed>
     */
    public static function withoutClientUses(array $content, array $clientUses): array
    {
        $drop = [];
        foreach ($clientUses as $use) {
            $id = (string) ($use['id'] ?? '');
            if ('' !== $id) {
                $drop[$id] = true;
            }
        }
        if ([] === $drop) {
            return $content;
        }

        $kept = [];
        foreach ($content as $block) {
            if (!\is_array($block)) {
                $kept[] = $block;
                continue;
            }
            if ('tool_use' === ($block['type'] ?? '') && isset($drop[(string) ($block['id'] ?? '')])) {
                continue;
            }
            $kept[] = $block;
        }

        return $kept;
    }
}
