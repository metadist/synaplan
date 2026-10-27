<?php

declare(strict_types=1);

namespace App\Service\Mcp\Notion;

use App\Entity\McpServerConfig;
use App\Entity\NotionActivity;
use App\Repository\NotionActivityRepository;
use Psr\Log\LoggerInterface;

/**
 * Remembers which Notion pages Synaplan created or changed, so the Notion
 * card can list them. Recording never breaks the write it describes.
 */
final readonly class NotionActivityLog
{
    public const RECENT_LIMIT = 10;

    private const PAGE_TOOLS = [
        'create-pages' => NotionActivity::ACTION_CREATED,
        'duplicate-page' => NotionActivity::ACTION_CREATED,
        'create-database' => NotionActivity::ACTION_CREATED,
        'update-page' => NotionActivity::ACTION_UPDATED,
    ];

    public function __construct(
        private NotionActivityRepository $activity,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed>                                      $arguments
     * @param array{content: list<array<string, mixed>>, isError: bool} $result
     */
    public function recordToolCall(McpServerConfig $server, string $tool, array $arguments, array $result): void
    {
        if ($result['isError'] || !NotionToolHints::isNotion($server->getUrl())) {
            return;
        }
        $action = self::PAGE_TOOLS[NotionToolHints::bare($tool)] ?? null;
        if (null === $action || self::isFileOnly($arguments)) {
            return;
        }

        $answer = $this->decode($result['content']);
        try {
            if (NotionActivity::ACTION_UPDATED === $action) {
                $pageId = NotionGateway::pageId(is_string($arguments['page_id'] ?? null) ? $arguments['page_id'] : '');
                $title = $this->titleOf($arguments['properties'] ?? null)
                    ?? (null !== $pageId ? $this->activity->lastTitle($server->getUserId(), $pageId) : null)
                    ?? '';
                $this->record($server->getUserId(), $action, $pageId, $title, null !== $pageId ? NotionGateway::pageUrl($pageId) : null);

                return;
            }

            $pages = is_array($arguments['pages'] ?? null) ? array_values($arguments['pages']) : [];
            foreach ($this->createdPages($answer) as $index => $page) {
                $title = $page['title'] ?? $this->titleOf(is_array($pages[$index] ?? null) ? ($pages[$index]['properties'] ?? null) : null) ?? '';
                $this->record($server->getUserId(), $action, $page['id'], $title, $page['url']);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('NotionActivityLog: could not record a Notion change', [
                'server_id' => $server->getId(),
                'tool' => $tool,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function record(int $userId, string $action, ?string $pageId, string $title, ?string $url): void
    {
        $this->activity->save(new NotionActivity($userId, $action, $pageId, trim($title), $url));
    }

    /**
     * @return list<array{id: int, action: string, title: string, url: string|null, created_at: int}>
     */
    public function recent(int $userId): array
    {
        return array_map(static fn (NotionActivity $row): array => [
            'id' => (int) $row->getId(),
            'action' => $row->getAction(),
            'title' => $row->getTitle(),
            'url' => $row->getUrl(),
            'created_at' => $row->getCreated(),
        ], $this->activity->recent($userId, self::RECENT_LIMIT));
    }

    /**
     * @param list<array<string, mixed>> $content
     *
     * @return array<mixed>
     */
    private function decode(array $content): array
    {
        $text = '';
        foreach ($content as $block) {
            if ('text' === ($block['type'] ?? null) && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }
        $decoded = json_decode(trim($text), true);

        return is_array($decoded) ? $decoded : ['text' => $text];
    }

    /**
     * Every page in a create answer, by its id or link.
     *
     * @param array<mixed> $answer
     *
     * @return list<array{id: string|null, url: string|null, title: string|null}>
     */
    private function createdPages(array $answer): array
    {
        $rows = is_array($answer['pages'] ?? null) ? $answer['pages'] : (array_is_list($answer) ? $answer : [$answer]);
        $pages = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $url = is_string($row['url'] ?? null) ? $row['url'] : null;
            $id = NotionGateway::pageId(is_string($row['id'] ?? null) ? $row['id'] : ($url ?? ''));
            if (null === $id && null === $url) {
                continue;
            }
            $title = $this->titleOf($row['properties'] ?? null) ?? (is_string($row['title'] ?? null) ? $row['title'] : null);
            $pages[] = ['id' => $id, 'url' => $url ?? NotionGateway::pageUrl($id), 'title' => $title];
        }
        if ([] === $pages && is_string($answer['text'] ?? null)
            && 1 === preg_match('/https:\/\/[a-z0-9.-]*notion\.(?:so|com|site)\/[^\s"\'<>)]+/i', $answer['text'], $match)) {
            $pages[] = ['id' => NotionGateway::pageId($match[0]), 'url' => $match[0], 'title' => null];
        }

        return $pages;
    }

    /**
     * A file placed by {@see NotionFileUpload}, which records its own row.
     *
     * @param array<string, mixed> $arguments
     */
    private static function isFileOnly(array $arguments): bool
    {
        $content = $arguments['content'] ?? null;

        return 'insert_content' === ($arguments['command'] ?? null) && is_string($content)
            && 1 === preg_match('/^\s*(?:<(?:file|image|embed|pdf|video|audio)\b[^>]*file-upload:\/\/[^>]*>(?:[^<]*<\/\w+>)?|!?\[[^\]]*\]\(file-upload:\/\/[^)]+\))\s*$/i', $content);
    }

    private function titleOf(mixed $properties): ?string
    {
        if (!is_array($properties)) {
            return null;
        }
        foreach (['title', 'Title', 'Name', 'name'] as $key) {
            if (is_string($properties[$key] ?? null) && '' !== trim($properties[$key])) {
                return trim($properties[$key]);
            }
        }

        return null;
    }
}
