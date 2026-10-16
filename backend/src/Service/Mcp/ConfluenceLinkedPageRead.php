<?php

declare(strict_types=1);

namespace App\Service\Mcp;

/**
 * Pages from one turn that were handed to a connected Confluence MCP server
 * instead of the public web reader.
 */
final readonly class ConfluenceLinkedPageRead
{
    /**
     * @param list<array{url: string, success: bool, serverName: string, text: string}> $pages
     */
    public function __construct(public array $pages)
    {
    }

    public function attempted(): bool
    {
        return [] !== $this->pages;
    }

    public function successCount(): int
    {
        return count(array_filter($this->pages, static fn (array $page): bool => $page['success']));
    }

    /**
     * @return list<string>
     */
    public function handledUrls(): array
    {
        return array_map(static fn (array $page): string => $page['url'], $this->pages);
    }

    /**
     * @param list<string> $urls
     */
    public function coversAll(array $urls): bool
    {
        if ([] === $urls || [] === $this->pages) {
            return false;
        }

        $handled = array_fill_keys($this->handledUrls(), true);
        foreach ($urls as $url) {
            if (!isset($handled[$url])) {
                return false;
            }
        }

        return true;
    }

    public function prompt(): string
    {
        if ([] === $this->pages) {
            return '';
        }

        $sections = [];
        foreach ($this->pages as $page) {
            $header = sprintf('--- URL: %s ---', $page['url']);
            $header .= sprintf("\nConnection: %s", $page['serverName']);
            if ($page['success']) {
                $sections[] = $header."\nContent:\n".$page['text'];
                continue;
            }
            $sections[] = $header."\nStatus: NOT READ. ".$page['text'];
        }

        return "## Linked Confluence pages\n"
            .'The user pasted a Confluence link. The system read it through their connected Confluence account, not the public web. '
            .'Answer from the content below. If a page is marked NOT READ, say that in one sentence and include the reason. '
            ."Do not ask them to paste or upload the page, and do not describe the public login screen.\n\n"
            .implode("\n\n", $sections);
    }
}
