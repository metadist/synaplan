<?php

declare(strict_types=1);

namespace App\Service\Research\Spike;

use App\Plug\WebSearch\WebSearchGateway;
use App\Service\Research\UrlContentService;

/**
 * Web search and page reading for one spike. Pages in one batch are read
 * through the existing guard; that reader fetches them one after another.
 */
final readonly class LiveSpikeSources implements SpikeSources
{
    public function __construct(
        private WebSearchGateway $search,
        private UrlContentService $pages,
        private LeadUrlFilter $urls,
    ) {
    }

    public function search(string $query, int $limit, int $userId): array
    {
        $raw = $this->search->search($query, ['count' => $limit], $userId);
        $hits = [];
        foreach ($raw['results'] ?? [] as $row) {
            if (!\is_array($row) || !\is_string($row['url'] ?? null) || '' === trim($row['url'])) {
                continue;
            }
            $hits[] = new SearchHit(
                $row['url'],
                \is_string($row['title'] ?? null) ? $row['title'] : '',
                \is_string($row['description'] ?? null) ? $row['description'] : '',
            );
            if (\count($hits) >= $limit) {
                break;
            }
        }

        return $hits;
    }

    public function read(string $url): PageBody
    {
        $page = $this->pages->fetchForReading($url);
        if (!$page->success || '' === trim($page->extractedText)) {
            return new PageBody('', [], $page->blockedReason ?? $page->error ?? 'no_text');
        }

        return new PageBody($page->extractedText, $this->links($page->extractedText));
    }

    /**
     * @return list<string>
     */
    private function links(string $text): array
    {
        preg_match_all('#https://[^\s<>"\'\])]+#', $text, $matches);
        $links = [];
        foreach ($matches[0] as $url) {
            $url = rtrim($url, '.,;');
            if (null !== $this->urls->canonical($url)) {
                $links[] = $url;
            }
        }

        return $links;
    }
}
