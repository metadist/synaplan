<?php

declare(strict_types=1);

namespace App\Service\Mcp\Notion;

/**
 * Two Notion reads the model rarely plans on its own:
 *
 * - meeting notes: one `query-meeting-notes` call for the time the person
 *   named, then an answer from those notes;
 * - a database question: search for the database, fetch its schema, read up
 *   to 50 rows, then answer from those rows.
 */
final class NotionReadPlan
{
    public const MEETINGS_TOOL = 'query-meeting-notes';
    public const ROWS_TOOL = 'query-data-sources';

    private const MEETINGS_ID = 'n_notion_meetings';
    private const DB_SEARCH_ID = 'n_notion_db_search';
    private const DB_FETCH_ID = 'n_notion_db_fetch';
    private const DB_ROWS_ID = 'n_notion_db_rows';
    private const REPLY_ID = 'n_notion_read_reply';
    private const ROW_LIMIT = 50;

    private const MEETINGS_REPLY = 'The Notion meeting notes are below. Answer the user\'s question from them, in the language of the question, and name each meeting with its date. If the list is empty, say Notion has no meeting notes for that time. If Notion could not be read, say so in one sentence and name the reason.';

    private const ROWS_REPLY = 'The rows of the Notion database are below. Answer the user\'s question from them, in the language of the question. Do not invent rows. If Notion could not read the rows, for example because the workspace plan does not allow database queries, say that plainly in one sentence and suggest opening the database in Notion. Nothing was changed in Notion.';

    private const MEETING_WORDS = '/\b(?:meetings?|meeting[- ]?notes?|besprechung\w*|protokoll\w*|reuni[óo]n\w*|r[ée]union\w*|toplant[ıi]\w*)\b/iu';

    private const DATABASE_WORDS = '/\b(?:datenbank\w*|databases?|tabelle\w*|base\s+de\s+(?:datos|donn[ée]es)|veritaban\w*)\b/iu';

    /** Words that are the request itself, not the database name. */
    private const STOP = [
        'notion', 'datenbank', 'database', 'tabelle', 'table', 'base', 'datos', 'données', 'donnees', 'veritabanı', 'veritabani',
        'meiner', 'meine', 'mein', 'meinem', 'der', 'die', 'das', 'den', 'dem', 'in', 'im', 'aus', 'von', 'zeig', 'zeige', 'mir',
        'alle', 'welche', 'wie', 'viele', 'was', 'steht', 'gibt', 'es', 'the', 'my', 'in', 'from', 'show', 'me', 'all', 'which',
        'how', 'many', 'what', 'list', 'rows', 'entries', 'einträge', 'eintraege', 'zeilen', 'de', 'la', 'mi', 'mis', 'du', 'des',
        'le', 'les', 'ma', 'mes', 'bitte', 'please', 'are', 'is', 'there', 'und', 'and', 'with', 'mit', 'for', 'für', 'fuer',
        'open', 'offen', 'offene', 'offenen', 'abiertas', 'abiertos', 'filas', 'muestra', 'ouvertes', 'ouverts', 'lignes', 'affiche',
        'montre', 'açık', 'acik', 'satırlarını', 'satirlarini', 'göster', 'goster', 'veritabanımın', 'veritabanimin',
    ];

    /**
     * @param array<string, mixed> $payload
     * @param list<string>|null    $allowed
     *
     * @return array<string, mixed>
     */
    public static function installMeetings(array $payload, string $messageText, int $serverId, string $meetingsTool, ?array $allowed = null): array
    {
        if ($serverId <= 0 || '' === $meetingsTool || 1 !== preg_match(self::MEETING_WORDS, $messageText)
            || !self::allowed($allowed, 'chat') || self::usesTool($payload, $meetingsTool)) {
            return $payload;
        }
        $tasks = self::tasks($payload);
        if (null === $tasks) {
            return $payload;
        }

        $read = [
            'id' => self::MEETINGS_ID,
            'capability' => 'mcp_fetch',
            'depends_on' => [],
            'inputs' => ['arguments' => ['filter' => self::meetingFilter($messageText)]],
            'params' => ['server_id' => $serverId, 'tool' => $meetingsTool],
        ];

        return self::withReply($payload, $tasks, [$read], self::MEETINGS_ID, self::MEETINGS_REPLY);
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>|null    $allowed
     *
     * @return array<string, mixed>
     */
    public static function installDatabase(array $payload, string $messageText, int $serverId, string $searchTool, string $fetchTool, string $rowsTool, ?array $allowed = null): array
    {
        if ($serverId <= 0 || '' === $searchTool || '' === $fetchTool || '' === $rowsTool
            || 1 !== preg_match(self::DATABASE_WORDS, $messageText)
            || !self::allowed($allowed, 'chat') || self::usesTool($payload, $rowsTool)) {
            return $payload;
        }
        $tasks = self::tasks($payload);
        $query = self::databaseQuery($messageText);
        if (null === $tasks || '' === $query) {
            return $payload;
        }

        $steps = [
            [
                'id' => self::DB_SEARCH_ID,
                'capability' => 'mcp_fetch',
                'depends_on' => [],
                'inputs' => ['arguments' => ['query' => $query]],
                'params' => ['server_id' => $serverId, 'tool' => $searchTool],
            ],
            [
                'id' => self::DB_FETCH_ID,
                'capability' => 'mcp_fetch',
                'depends_on' => [self::DB_SEARCH_ID],
                'inputs' => ['arguments' => ['id' => '$'.self::DB_SEARCH_ID.'.text']],
                'params' => ['server_id' => $serverId, 'tool' => $fetchTool],
            ],
            [
                'id' => self::DB_ROWS_ID,
                'capability' => 'mcp_fetch',
                'depends_on' => [self::DB_FETCH_ID],
                'inputs' => ['arguments' => ['data' => [
                    'mode' => 'rows',
                    'data_source_url' => '$'.self::DB_FETCH_ID.'.text',
                    'limit' => self::ROW_LIMIT,
                ]]],
                'params' => ['server_id' => $serverId, 'tool' => $rowsTool],
            ],
        ];

        return self::withReply($payload, $tasks, $steps, self::DB_ROWS_ID, self::ROWS_REPLY);
    }

    /**
     * The first `collection://` data source in a fetched database, for the
     * rows step that only received the whole fetch result.
     */
    public static function dataSourceUrl(string $text): ?string
    {
        return 1 === preg_match('/collection:\/\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $text, $match)
            ? $match[0]
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function meetingFilter(string $text): array
    {
        $lower = mb_strtolower($text);
        $date = match (true) {
            1 === preg_match('/\b(?:today|heute|hoy|aujourd\'hui|bugün|bugun)\b/u', $lower) => ['operator' => 'date_is', 'value' => ['type' => 'relative', 'value' => 'today']],
            1 === preg_match('/\b(?:yesterday|gestern|ayer|d\'hier|dün|dun)\b/u', $lower) => ['operator' => 'date_is', 'value' => ['type' => 'relative', 'value' => 'yesterday']],
            1 === preg_match('/\b(?:month|monat\w*|mes|mois|bu\s+ay|geçen\s+ay)\b/u', $lower) => ['operator' => 'date_is_within', 'value' => ['type' => 'relative', 'value' => 'the_past_month']],
            default => ['operator' => 'date_is_within', 'value' => ['type' => 'relative', 'value' => 'the_past_week']],
        };

        return ['operator' => 'and', 'filters' => [['property' => 'created_time', 'filter' => $date]]];
    }

    public static function databaseQuery(string $text): string
    {
        if (1 === preg_match('/"([^"]{2,80})"|„([^“]{2,80})“|«([^»]{2,80})»/u', $text, $match)) {
            foreach (array_slice($match, 1) as $quoted) {
                if ('' !== trim($quoted)) {
                    return trim($quoted);
                }
            }
        }
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];
        $kept = array_filter($words, static fn (string $word): bool => mb_strlen($word) >= 3 && !in_array($word, self::STOP, true));

        return mb_substr(implode(' ', array_slice(array_values($kept), 0, 6)), 0, 80);
    }

    /**
     * @param array<string, mixed>       $payload
     * @param list<mixed>                $tasks
     * @param list<array<string, mixed>> $steps
     *
     * @return array<string, mixed>
     */
    private static function withReply(array $payload, array $tasks, array $steps, string $sourceId, string $instruction): array
    {
        $replyId = is_string($payload['reply_node'] ?? null) ? $payload['reply_node'] : '';
        $text = $instruction."\n".'$'.$sourceId.'.text';
        $kept = [];
        $replaced = false;
        foreach ($tasks as $task) {
            if (!is_array($task)) {
                continue;
            }
            if ($replyId === ($task['id'] ?? null) && 'chat' === ($task['capability'] ?? null)) {
                $task['depends_on'] = [$sourceId];
                $task['inputs'] = ['text' => $text];
                $replaced = true;
            } elseif ('mcp_fetch' === ($task['capability'] ?? null) || $replyId === ($task['id'] ?? null)) {
                continue;
            }
            $kept[] = $task;
        }
        if (!$replaced) {
            $kept[] = [
                'id' => self::REPLY_ID,
                'capability' => 'chat',
                'depends_on' => [$sourceId],
                'inputs' => ['text' => $text],
                'params' => [],
            ];
            $payload['reply_node'] = self::REPLY_ID;
        }

        $payload['tasks'] = array_merge($steps, self::withoutDanglingDeps($kept, $steps));
        $payload['version'] ??= 1;

        return $payload;
    }

    /**
     * Removed fetch steps leave no dependency behind.
     *
     * @param list<array<string, mixed>> $tasks
     * @param list<array<string, mixed>> $steps
     *
     * @return list<array<string, mixed>>
     */
    private static function withoutDanglingDeps(array $tasks, array $steps): array
    {
        $known = [];
        foreach (array_merge($steps, $tasks) as $task) {
            if (is_string($task['id'] ?? null)) {
                $known[$task['id']] = true;
            }
        }
        foreach ($tasks as $index => $task) {
            $deps = is_array($task['depends_on'] ?? null) ? $task['depends_on'] : [];
            $tasks[$index]['depends_on'] = array_values(array_filter($deps, static fn (mixed $dep): bool => is_string($dep) && isset($known[$dep])));
        }

        return $tasks;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>|null
     */
    private static function tasks(array $payload): ?array
    {
        $tasks = $payload['tasks'] ?? null;

        return is_array($tasks) && array_is_list($tasks) ? $tasks : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function usesTool(array $payload, string $tool): bool
    {
        foreach ((array) ($payload['tasks'] ?? []) as $task) {
            if (is_array($task) && ($task['params']['tool'] ?? null) === $tool) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string>|null $allowed
     */
    private static function allowed(?array $allowed, string $reply): bool
    {
        return null === $allowed || (in_array('mcp_fetch', $allowed, true) && in_array($reply, $allowed, true));
    }
}
