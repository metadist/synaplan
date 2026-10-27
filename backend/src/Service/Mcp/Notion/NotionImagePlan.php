<?php

declare(strict_types=1);

namespace App\Service\Mcp\Notion;

/**
 * A request for an image from Notion still needs a search, then a step that
 * turns the first picture on those pages into a chat file. The model often
 * emits only a chat node once an earlier turn already talked about Notion.
 */
final class NotionImagePlan
{
    public const URLS_TOOL = 'get-file-download-urls';

    private const SEARCH_ID = 'n_notion_search';

    private const IMAGE_ID = 'n_notion_image';

    private const REPLY_ID = 'n_notion_reply';

    /** Words that are the request itself, not a page to search for. */
    private const STOP = [
        'such' => true, 'suche' => true, 'sucht' => true, 'mal' => true, 'bitte' => true,
        'meinem' => true, 'meiner' => true, 'meinen' => true, 'mein' => true, 'meine' => true,
        'ganzen' => true, 'ganze' => true, 'ganz' => true, 'gesamten' => true, 'gesamtes' => true,
        'notion' => true, 'nach' => true, 'bild' => true, 'bilder' => true, 'bildern' => true,
        'foto' => true, 'fotos' => true, 'image' => true, 'images' => true, 'picture' => true, 'pictures' => true,
        'und' => true, 'wenn' => true, 'das' => true, 'der' => true, 'die' => true, 'den' => true, 'dem' => true,
        'erste' => true, 'ersten' => true, 'findest' => true, 'finde' => true, 'finden' => true,
        'hänge' => true, 'haenge' => true, 'häng' => true, 'anhängen' => true, 'anhaengen' => true, 'anhang' => true,
        'hier' => true, 'chat' => true, 'mir' => true, 'ein' => true, 'eine' => true, 'einem' => true, 'einer' => true,
        'the' => true, 'my' => true, 'whole' => true, 'for' => true, 'attach' => true, 'attached' => true,
        'first' => true, 'find' => true, 'here' => true, 'this' => true, 'workspace' => true, 'seite' => true, 'page' => true,
    ];

    /**
     * @param array<string, mixed> $payload
     * @param list<string>|null    $allowed
     *
     * @return array<string, mixed>
     */
    public static function install(array $payload, string $messageText, int $serverId, string $searchTool, string $urlsTool, string $serverName = '', ?array $allowed = null): array
    {
        $tasks = $payload['tasks'] ?? null;
        if (!self::wantsAttachedImage($messageText) || !self::mentions($messageText, $serverName)
            || $serverId <= 0 || '' === $searchTool || '' === $urlsTool
            || !is_array($tasks) || !array_is_list($tasks)) {
            return $payload;
        }
        if (null !== $allowed && (!in_array('mcp_fetch', $allowed, true) || !in_array('compose_reply', $allowed, true))) {
            return $payload;
        }
        foreach ($tasks as $task) {
            if (is_array($task) && ($task['params']['tool'] ?? null) === $urlsTool) {
                return $payload;
            }
        }

        $searchId = null;
        $removed = [];
        $replyId = is_string($payload['reply_node'] ?? null) ? $payload['reply_node'] : '';
        $kept = [];
        foreach ($tasks as $task) {
            if (!is_array($task)) {
                continue;
            }
            $id = is_string($task['id'] ?? null) ? $task['id'] : '';
            $bare = self::bare(is_string($task['params']['tool'] ?? null) ? $task['params']['tool'] : '');
            if ('download-attachment' === $bare || ($id === $replyId && in_array($task['capability'] ?? null, ['chat', 'compose_reply'], true))) {
                $removed[$id] = true;
                continue;
            }
            if (null === $searchId && 'mcp_fetch' === ($task['capability'] ?? null) && ($task['params']['tool'] ?? null) === $searchTool && '' !== $id) {
                $searchId = $id;
            }
            $kept[] = $task;
        }
        if (null === $searchId && isset($removed[self::SEARCH_ID])) {
            return $payload;
        }

        $searchId ??= self::SEARCH_ID;
        $prefix = [];
        if (self::SEARCH_ID === $searchId) {
            $prefix[] = [
                'id' => $searchId,
                'capability' => 'mcp_fetch',
                'depends_on' => [],
                'inputs' => ['arguments' => ['query' => self::query($messageText)]],
                'params' => ['server_id' => $serverId, 'tool' => $searchTool],
            ];
        }
        $prefix[] = [
            'id' => self::IMAGE_ID,
            'capability' => 'mcp_fetch',
            'depends_on' => [$searchId],
            'inputs' => ['arguments' => ['pages' => '$'.$searchId.'.text']],
            'params' => ['server_id' => $serverId, 'tool' => $urlsTool],
        ];
        $prefix[] = [
            'id' => self::REPLY_ID,
            'capability' => 'compose_reply',
            'depends_on' => [self::IMAGE_ID],
            'inputs' => [
                'text' => '$'.self::IMAGE_ID.'.text',
                'attachments' => ['$'.self::IMAGE_ID.'.file'],
            ],
            'params' => [],
        ];

        $payload['tasks'] = array_merge($prefix, self::withoutRemovedDeps($kept, $removed));
        $payload['reply_node'] = self::REPLY_ID;
        $payload['version'] ??= 1;

        return $payload;
    }

    public static function wantsAttachedImage(string $text): bool
    {
        $image = 1 === preg_match('/\b(bilder?n?|fotos?|images?|pictures?|png|jpe?g|gif|webp)\b/iu', $text);
        $attach = 1 === preg_match('/anhäng|anhaeng|h[äa]ng|attach|zeig|schick|sende|\bsend\b/iu', $text);

        return $image && $attach;
    }

    public static function mentions(string $text, string $serverName = ''): bool
    {
        if (1 === preg_match('/\bnotion\b/iu', $text)) {
            return true;
        }
        $name = trim($serverName);
        if (mb_strlen($name) < 3) {
            return false;
        }

        return 1 === preg_match('/(?<![\p{L}\p{N}_])'.preg_quote($name, '/').'(?![\p{L}\p{N}_])/iu', $text);
    }

    public static function query(string $text): string
    {
        if (1 === preg_match('/"([^"]{2,80})"|„([^“]{2,80})“/u', $text, $match)) {
            $quoted = $match[1];
            if ('' === $quoted && isset($match[2])) {
                $quoted = $match[2];
            }
            $quoted = trim($quoted);
            if ('' !== $quoted && !isset(self::STOP[mb_strtolower($quoted)])) {
                return $quoted;
            }
        }

        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];
        $kept = [];
        foreach ($words as $word) {
            if (mb_strlen($word) < 3 || isset(self::STOP[$word])) {
                continue;
            }
            $kept[] = $word;
        }
        $query = implode(' ', $kept);

        return mb_strlen($query) > 80 ? mb_substr($query, 0, 80) : $query;
    }

    private static function bare(string $name): string
    {
        $name = strtolower($name);

        return str_starts_with($name, 'notion-') ? substr($name, strlen('notion-')) : $name;
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @param array<string, true>        $removed
     *
     * @return list<array<string, mixed>>
     */
    private static function withoutRemovedDeps(array $tasks, array $removed): array
    {
        if ([] === $removed) {
            return $tasks;
        }
        foreach ($tasks as $index => $task) {
            $deps = $task['depends_on'] ?? null;
            if (!is_array($deps)) {
                continue;
            }
            $tasks[$index]['depends_on'] = array_values(array_filter(
                $deps,
                static fn (mixed $dep): bool => is_string($dep) && !isset($removed[$dep]),
            ));
        }

        return $tasks;
    }
}
