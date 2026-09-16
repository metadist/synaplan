<?php

declare(strict_types=1);

namespace App\Service\Research;

use App\Service\UrlContentResult;

/**
 * Outcome of reading the links a user pasted: every attempted page (read or
 * not) plus the question-fitted text of the ones that were readable.
 */
final readonly class ReadPagesResult
{
    /**
     * @param list<UrlContentResult> $pages      one entry per requested URL, in request order
     * @param array<string, string>  $fittedText requested URL => condensed/verbatim page text
     */
    public function __construct(
        public array $pages,
        public array $fittedText,
    ) {
    }

    public function successCount(): int
    {
        return count(array_filter($this->pages, static fn (UrlContentResult $p): bool => $p->success && '' !== $p->extractedText));
    }

    public function hasReadablePage(): bool
    {
        return $this->successCount() > 0;
    }

    /**
     * Short description of the readable pages — used to derive a web-search
     * query when the user's message is nothing but a link.
     */
    public function contextForQuery(int $maxChars = 1500): ?string
    {
        $parts = [];
        foreach ($this->pages as $page) {
            if (!$page->success || !isset($this->fittedText[$page->url])) {
                continue;
            }
            $parts[] = trim(('' !== $page->title ? $page->title."\n" : '').mb_substr($this->fittedText[$page->url], 0, $maxChars));
        }

        return [] === $parts ? null : implode("\n\n", $parts);
    }

    /**
     * @return list<array{url: string, final_url: string|null, title: string, fetched: bool, blocked_reason: string|null}>
     */
    public function toClientList(): array
    {
        $out = [];
        foreach ($this->pages as $page) {
            $out[] = [
                'url' => $page->url,
                'final_url' => $page->finalUrl,
                'title' => $page->title,
                'fetched' => $page->success && '' !== $page->extractedText,
                'blocked_reason' => $page->blockedReason,
            ];
        }

        return $out;
    }
}
