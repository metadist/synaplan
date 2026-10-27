<?php

declare(strict_types=1);

namespace App\Service\Mcp\Notion;

use App\Entity\McpServerConfig;
use App\Service\Mcp\McpClient;
use App\Service\Mcp\McpClientException;
use App\Service\Mcp\McpToolRegistry;
use Psr\Log\LoggerInterface;

/**
 * A Notion title change named by the page title has no page id yet.
 * Search first, then call update-page with that id.
 */
final readonly class NotionPageWrite
{
    public const UPDATE_TOOL = 'update-page';

    /**
     * "<title> of <page> to <new>" in de/en/es/fr, "<page> başlığını <new>
     * olarak değiştir" in tr, and the plain rename verbs. Group 1 is the
     * current title, group 2 the new one.
     */
    private const TITLE_PATTERNS = [
        '/(?:titel|title|t[íi]tulo|titre)\s+(?:von|of|from|del|de\s+la|de|du)\s+(?:der\s+seite\s+|the\s+page\s+|la\s+p[áa]gina\s+|la\s+page\s+)?(.+?)\s+(?:zu|auf|in|to|into|a|en|por)\s+(.+)$/iu',
        '/^(.+?)\s+(?:sayfas[ıi]n[ıi]n\s+)?(?:başl[ıi]ğ[ıi]n[ıi]|ad[ıi]n[ıi])\s+(.+?)\s+olarak\s+(?:değiştir|yeniden\s+adland[ıi]r)\w*$/iu',
        '/(?:rename|renombra(?:r)?|renomme(?:r)?)\s+(?:the\s+page\s+|la\s+p[áa]gina\s+|la\s+page\s+|page\s+|p[áa]gina\s+)?(.+?)\s+(?:to|into|a|como|en)\s+(.+)$/iu',
        '/benenne\s+(?:die\s+seite\s+)?(.+?)\s+(?:in|zu)\s+(.+?)\s+um$/iu',
    ];

    private const REPLY = 'The Notion update result is below. Answer in the language of the user\'s question. If the update worked, say the page title was changed and to what. If it failed, say the title was not changed and why. Do not ask for a page link.';

    public function __construct(
        private McpClient $client,
        private McpToolRegistry $tools,
        private NotionFetchArguments $pages,
        private LoggerInterface $logger,
    ) {
    }

    public static function argumentHint(string $serverUrl, string $toolName): string
    {
        if (!self::isUpdateTool($serverUrl, $toolName)) {
            return '';
        }

        return ' — page_id is one page id from a search result, never the page title';
    }

    /**
     * When the person asks to rename a page and the plan never writes,
     * insert a search and an update-page step in front of the reply.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function install(array $payload, string $messageText, int $serverId, string $searchTool, string $updateTool): array
    {
        $titles = self::titles($messageText);
        $tasks = $payload['tasks'] ?? null;
        if (null === $titles || $serverId <= 0 || '' === $searchTool || '' === $updateTool || !is_array($tasks) || !array_is_list($tasks)) {
            return $payload;
        }

        foreach ($tasks as $task) {
            if (is_array($task) && 'mcp_action' === ($task['capability'] ?? null) && ($task['params']['tool'] ?? null) === $updateTool) {
                return $payload;
            }
        }

        [$current, $next] = $titles;
        $searchId = null;
        foreach ($tasks as $task) {
            if (is_array($task) && 'mcp_fetch' === ($task['capability'] ?? null) && ($task['params']['tool'] ?? null) === $searchTool && is_string($task['id'] ?? null)) {
                $searchId = $task['id'];
                break;
            }
        }

        $addedSearch = false;
        if (null === $searchId) {
            $searchId = 'n_notion_search';
            $addedSearch = true;
        }
        $updateId = 'n_notion_update';
        $replyId = is_string($payload['reply_node'] ?? null) ? $payload['reply_node'] : '';
        $rewritten = [];
        $replyFound = false;
        foreach ($tasks as $task) {
            if (!is_array($task)) {
                continue;
            }
            if ($replyId === ($task['id'] ?? null) && 'chat' === ($task['capability'] ?? null)) {
                $depends = array_values(array_filter((array) ($task['depends_on'] ?? []), 'is_string'));
                if (!in_array($updateId, $depends, true)) {
                    $depends[] = $updateId;
                }
                $inputs = is_array($task['inputs'] ?? null) ? $task['inputs'] : [];
                $inputs['text'] = self::REPLY."\n".'$'.$updateId.'.text';
                $task['depends_on'] = $depends;
                $task['inputs'] = $inputs;
                $replyFound = true;
            }
            $rewritten[] = $task;
        }

        $prefix = [];
        if ($addedSearch) {
            $prefix[] = [
                'id' => $searchId,
                'capability' => 'mcp_fetch',
                'depends_on' => [],
                'inputs' => ['arguments' => ['query' => $current]],
                'params' => ['server_id' => $serverId, 'tool' => $searchTool],
            ];
        }
        $prefix[] = [
            'id' => $updateId,
            'capability' => 'mcp_action',
            'depends_on' => [$searchId],
            'inputs' => [
                'arguments' => [
                    'page_id' => '$'.$searchId.'.text',
                    'command' => 'update_properties',
                    'properties' => ['title' => $next],
                ],
            ],
            'params' => ['server_id' => $serverId, 'tool' => $updateTool],
        ];
        if (!$replyFound) {
            $replyId = 'n_notion_reply';
            $prefix[] = [
                'id' => $replyId,
                'capability' => 'chat',
                'depends_on' => [$updateId],
                'inputs' => ['text' => self::REPLY."\n".'$'.$updateId.'.text'],
                'params' => [],
            ];
            $payload['reply_node'] = $replyId;
        }

        $payload['tasks'] = array_merge($prefix, $rewritten);
        $payload['version'] ??= 1;

        return $payload;
    }

    /**
     * @return array{0: string, 1: string}|null current title, new title
     */
    public static function titles(string $text): ?array
    {
        if (!self::isTitleChange($text)) {
            return null;
        }

        $quoted = self::quoted($text);
        if (count($quoted) >= 2) {
            return self::pair($quoted[0], $quoted[count($quoted) - 1]);
        }

        $stripped = trim((string) preg_replace('/[?!.\s]+$/u', '', $text));
        $stripped = trim((string) preg_replace('/\s+(?:ändern|aendern|umbenennen|bitte|please|por\s+favor|s\'il\s+(?:te|vous)\s+pla[iî]t|lütfen)\s*$/iu', '', $stripped));
        foreach (self::TITLE_PATTERNS as $pattern) {
            if (1 === preg_match($pattern, $stripped, $match)) {
                return self::pair($match[1], $match[2]);
            }
        }

        return null;
    }

    /**
     * @param array{name: string, inputSchema?: array<string, mixed>} $tool
     * @param array<string, mixed>                                    $arguments
     *
     * @return array<string, mixed>
     */
    public function complete(McpServerConfig $server, array $tool, array $arguments, string $sourceText): array
    {
        if (!self::isUpdateTool($server->getUrl(), (string) $tool['name'])) {
            return $arguments;
        }

        $current = $arguments['page_id'] ?? null;
        $pageId = $this->pages->pageIdFrom($current, $sourceText);
        if (null === $pageId) {
            $query = $this->searchQuery($current, $sourceText);
            if (null !== $query) {
                $pageId = $this->search($server, $query, $sourceText);
            }
        }
        if (null !== $pageId && $pageId !== $current) {
            $arguments['page_id'] = $pageId;
            $this->logger->info('NotionPageWrite: resolved the page id before the title change', [
                'server_id' => $server->getId(),
            ]);
        }

        $titles = self::titles($sourceText);
        if (null !== $titles && !is_string($arguments['command'] ?? null)) {
            $arguments['command'] = 'update_properties';
            if (!is_array($arguments['properties'] ?? null)) {
                $arguments['properties'] = ['title' => $titles[1]];
            }
        }

        return $arguments;
    }

    private function search(McpServerConfig $server, string $query, string $sourceText): ?string
    {
        $tool = null;
        foreach ($this->tools->toolsFor($server) as $candidate) {
            if ('search' === self::bare($candidate['name'])) {
                $tool = $candidate['name'];
                break;
            }
        }
        if (null === $tool) {
            return null;
        }

        try {
            $result = $this->client->callTool($server, $tool, ['query' => $query]);
        } catch (McpClientException $e) {
            $this->logger->warning('NotionPageWrite: search before the title change failed', [
                'server_id' => $server->getId(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
        if ($result['isError']) {
            return null;
        }

        $text = '';
        foreach ($result['content'] as $block) {
            if ('text' === ($block['type'] ?? null) && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        return $this->pages->pageIdFrom(trim($text), $sourceText);
    }

    private function searchQuery(mixed $current, string $sourceText): ?string
    {
        if (is_string($current)) {
            $trimmed = trim($current);
            if ('' !== $trimmed && !str_starts_with($trimmed, '$') && !str_starts_with($trimmed, '{') && !str_starts_with($trimmed, '[')) {
                return mb_substr($trimmed, 0, 200);
            }
        }

        return self::titles($sourceText)[0] ?? null;
    }

    private static function isUpdateTool(string $serverUrl, string $toolName): bool
    {
        return NotionToolHints::isNotion($serverUrl) && self::UPDATE_TOOL === self::bare($toolName);
    }

    private static function bare(string $name): string
    {
        return NotionToolHints::bare($name);
    }

    private static function isTitleChange(string $text): bool
    {
        $lower = mb_strtolower($text);
        if (1 === preg_match('/\brename\b|umbenenn|\bbenenne\b|renombr|renomm|yeniden\s+adland/u', $lower)) {
            return true;
        }

        $mentionsTitle = 1 === preg_match('/titel|title|t[íi]tulo|titre|başl[ıi]ğ|(?<!\p{L})ad[ıi]n[ıi](?!\p{L})/u', $lower);
        $mentionsChange = 1 === preg_match('/änder|aender|\bchange|cambi|modifi|değiştir/u', $lower);

        return $mentionsTitle && $mentionsChange;
    }

    /**
     * @return list<string>
     */
    private static function quoted(string $text): array
    {
        preg_match_all('/"([^"]+)"|„([^“]+)“|«([^»]+)»|“([^”]+)”/u', $text, $matches, PREG_SET_ORDER);
        $titles = [];
        foreach ($matches as $row) {
            $value = '';
            for ($i = 1, $n = count($row); $i < $n; ++$i) {
                if ('' !== ($row[$i] ?? '')) {
                    $value = $row[$i];
                    break;
                }
            }
            $value = trim($value);
            if (mb_strlen($value) >= 2) {
                $titles[] = $value;
            }
        }

        return $titles;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function pair(string $current, string $next): ?array
    {
        $current = self::clean($current);
        $next = self::clean($next);
        if (!self::usable($current) || !self::usable($next) || mb_strtolower($current) === mb_strtolower($next)) {
            return null;
        }

        return [$current, $next];
    }

    private static function clean(string $title): string
    {
        $title = trim($title, " \t\n\r\0\x0B\"'„“”«»");
        $title = trim((string) preg_replace('/^(?:die\s+seite|the\s+page|la\s+p[áa]gina|la\s+page|seite|page|p[áa]gina)\s+/iu', '', $title));

        return trim($title, " \t.?!¿¡");
    }

    private static function usable(string $title): bool
    {
        if (mb_strlen($title) < 2 || mb_strlen($title) > 120) {
            return false;
        }

        return !in_array(mb_strtolower($title), ['notion', 'seite', 'page', 'página', 'pagina', 'sayfa', 'titel', 'title', 'título', 'titulo', 'titre', 'başlık'], true);
    }
}
