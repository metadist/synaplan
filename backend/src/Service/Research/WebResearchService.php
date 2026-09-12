<?php

declare(strict_types=1);

namespace App\Service\Research;

use App\Plug\PlugConfigService;
use App\Service\Context\ContextCondenser;
use App\Service\UrlContentService;
use Psr\Log\LoggerInterface;

/**
 * Turns "10 search snippets" into evidence: reads the top result pages,
 * condenses each one with the user's question as the lens, and attaches the
 * result to the search results array the answering model already receives.
 *
 * Also reads links the user pasted into the chat (redirects and shortlink
 * interstitials resolved) so the model answers from the article, not from
 * the URL string.
 *
 * Everything here is best-effort and time-boxed: a page that cannot be read
 * (login wall, bot block, timeout) is reported as such and never breaks the
 * turn.
 */
final readonly class WebResearchService
{
    /** Wall-clock budget for reading result pages of ONE search. */
    private const READ_DEADLINE_SECONDS = 25;

    /** A page shorter than this adds nothing beyond its snippet. */
    private const MIN_USEFUL_PAGE_CHARS = 400;

    /** Per-page floor so the budget split never produces a useless sliver. */
    private const MIN_PAGE_BUDGET_CHARS = 2500;

    /** Hosts behind a login wall or with no readable article body. */
    private const SKIP_HOSTS = ['linkedin.com', 'facebook.com', 'instagram.com', 'x.com', 'twitter.com', 'tiktok.com', 'youtube.com', 'youtu.be', 'vimeo.com', 'pinterest.com'];

    /** File-like URLs the HTML reader cannot turn into text. */
    private const SKIP_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', 'gz', 'mp3', 'mp4', 'mov', 'avi', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];

    public function __construct(
        private UrlContentService $urlContentService,
        private ContextCondenser $condenser,
        private PlugConfigService $plugConfig,
        private LoggerInterface $logger,
    ) {
    }

    public function isDeepSearchEnabled(): bool
    {
        return $this->plugConfig->isWebSearchReadPagesEnabled() && $this->plugConfig->webSearchReadPagesMax() > 0;
    }

    public function isUrlReadEnabled(): bool
    {
        return $this->plugConfig->isUrlReadEnabled();
    }

    /**
     * Read the top result pages and attach them to the results.
     *
     * Each selected result gains `page_content` (question-aware condensed text
     * or the full text when short), `final_url`, `fetched` (bool) and, when a
     * page could not be read, `blocked_reason`. The array also gains
     * `pages_read` / `pages_attempted` counters. Results keep their order so
     * citation numbers [1]…[n] stay stable.
     *
     * @param array<string, mixed>                                                            $searchResults legacy search results array (`query`, `results`)
     * @param callable(string $status, string $message, array<string, mixed> $meta):void|null $onProgress
     *
     * @return array<string, mixed>
     */
    public function deepen(array $searchResults, string $question, ?int $userId, ?callable $onProgress = null, ?int $maxPages = null, ?int $budgetChars = null): array
    {
        $results = $searchResults['results'] ?? null;
        if (!is_array($results) || [] === $results) {
            return $searchResults;
        }

        $maxPages ??= $this->plugConfig->webSearchReadPagesMax();
        $budgetChars ??= $this->plugConfig->webSearchReadPagesBudgetChars();
        if ($maxPages <= 0) {
            return $searchResults;
        }

        $selected = $this->selectUrls($results, $maxPages);
        if ([] === $selected) {
            return $searchResults;
        }

        $perPageBudget = max(self::MIN_PAGE_BUDGET_CHARS, (int) floor($budgetChars / count($selected)));
        $started = microtime(true);
        $attempted = 0;
        $read = 0;
        $hosts = array_map(fn (string $url): string => $this->hostLabel($url), $selected);

        // Start every page's first hop now: the transfers overlap in the HTTP
        // client while the loop below consumes them one by one, so three
        // slow news sites cost the slowest one, not the sum of all three.
        $firstHops = [];
        foreach ($selected as $index => $url) {
            $firstHops[$index] = $this->urlContentService->startReading($url);
        }

        if (null !== $onProgress) {
            $onProgress('reading_pages', sprintf('Reading %d web page%s...', count($selected), 1 === count($selected) ? '' : 's'), [
                'pages_total' => count($selected),
                'pages_read' => 0,
                'hosts' => array_values($hosts),
                'stage' => 'fetching',
            ]);
        }

        // Fetch every page first. Condensing used to sit between fetches and
        // added ~2.7 s per extra page on the critical path; extractive fitting
        // below is instant, so the wall clock is the slowest fetch, not the sum.
        $fetchedPages = [];
        foreach ($selected as $index => $url) {
            if (microtime(true) - $started > self::READ_DEADLINE_SECONDS) {
                $this->logger->info('WebResearchService: read deadline reached, skipping remaining pages', [
                    'attempted' => $attempted,
                    'remaining' => count($selected) - $attempted,
                ]);
                break;
            }

            ++$attempted;
            if (null !== $onProgress) {
                $onProgress('reading_pages', sprintf('Reading %s...', $hosts[$index]), [
                    'pages_total' => count($selected),
                    'pages_read' => 0,
                    'hosts' => array_values($hosts),
                    'current_host' => $hosts[$index],
                    'stage' => 'fetching',
                ]);
            }
            $fetchedPages[$index] = $this->urlContentService->fetchForReading($url, max($perPageBudget * 3, UrlContentService::DEFAULT_READ_TEXT_LENGTH), $firstHops[$index]);
        }

        foreach ($fetchedPages as $index => $page) {
            $url = $selected[$index];
            if (!$page->success || mb_strlen($page->extractedText) < self::MIN_USEFUL_PAGE_CHARS) {
                $results[$index]['fetched'] = false;
                if (null !== $page->blockedReason) {
                    $results[$index]['blocked_reason'] = $page->blockedReason;
                }
                continue;
            }

            if (null !== $onProgress && mb_strlen($page->extractedText) > $perPageBudget) {
                $onProgress('reading_pages', sprintf('Condensing %s...', $hosts[$index]), [
                    'pages_total' => count($selected),
                    'pages_read' => $read,
                    'hosts' => array_values($hosts),
                    'current_host' => $hosts[$index],
                    'stage' => 'condensing',
                ]);
            }

            $fitted = $this->condenser->fit($page->extractedText, $question, $perPageBudget, $userId, preferFastModel: true, preferExtractive: true);
            $results[$index]['page_content'] = $fitted->text;
            $results[$index]['page_content_strategy'] = $fitted->strategy;
            $results[$index]['final_url'] = $page->finalUrl ?? $url;
            $results[$index]['fetched'] = true;
            if ('' === trim((string) ($results[$index]['title'] ?? '')) && '' !== $page->title) {
                $results[$index]['title'] = $page->title;
            }
            ++$read;

            if (null !== $onProgress) {
                $onProgress('reading_pages', sprintf('Read %d of %d web pages...', $read, count($selected)), [
                    'pages_total' => count($selected),
                    'pages_read' => $read,
                    'hosts' => array_values($hosts),
                    'current_host' => $hosts[$index],
                    'stage' => 'fetching',
                ]);
            }
        }

        $searchResults['results'] = array_values($results);
        $searchResults['pages_read'] = $read;
        $searchResults['pages_attempted'] = $attempted;

        $this->logger->info('WebResearchService: deepened search results', [
            'query' => $searchResults['query'] ?? '',
            'selected' => count($selected),
            'attempted' => $attempted,
            'read' => $read,
            'seconds' => round(microtime(true) - $started, 2),
        ]);

        return $searchResults;
    }

    /**
     * Read links the user pasted. Successful pages are condensed to the
     * question when large; blocked pages are kept with their reason so the
     * prompt can say "this link needs a login" instead of hallucinating.
     *
     * @param list<string>                                                                    $urls
     * @param callable(string $status, string $message, array<string, mixed> $meta):void|null $onProgress
     */
    public function readMentionedUrls(array $urls, string $question, ?int $userId, ?callable $onProgress = null, ?int $maxUrls = null, int $budgetChars = 20000): ReadPagesResult
    {
        $maxUrls ??= $this->plugConfig->urlReadMax();
        $urls = array_values(array_unique(array_filter($urls, static fn (string $u): bool => '' !== trim($u))));
        $urls = array_slice($urls, 0, max(1, $maxUrls));
        if ([] === $urls) {
            return new ReadPagesResult([], []);
        }

        if (null !== $onProgress) {
            $onProgress('fetching_urls', sprintf('Reading %d link%s...', count($urls), 1 === count($urls) ? '' : 's'), ['urls_total' => count($urls)]);
        }

        $perPageBudget = max(self::MIN_PAGE_BUDGET_CHARS, (int) floor($budgetChars / count($urls)));
        $firstHops = [];
        foreach ($urls as $url) {
            $firstHops[$url] = $this->urlContentService->startReading($url);
        }

        $pages = [];
        foreach ($urls as $url) {
            $pages[] = $this->urlContentService->fetchForReading($url, max($perPageBudget * 3, UrlContentService::DEFAULT_READ_TEXT_LENGTH), $firstHops[$url]);
        }

        $fitted = [];
        foreach ($pages as $page) {
            if ($page->success && '' !== $page->extractedText) {
                $fitted[$page->url] = $this->condenser->fit($page->extractedText, $question, $perPageBudget, $userId, preferFastModel: true, preferExtractive: true)->text;
            }
        }

        $result = new ReadPagesResult($pages, $fitted);

        if (null !== $onProgress) {
            $onProgress('urls_fetched', sprintf('Read %d of %d link%s', $result->successCount(), count($urls), 1 === count($urls) ? '' : 's'), [
                'urls_total' => count($urls),
                'urls_read' => $result->successCount(),
                'pages' => $result->toClientList(),
            ]);
        }

        return $result;
    }

    /** "www.finanzen.net" → "finanzen.net": what the progress line shows while a page loads. */
    private function hostLabel(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return '' !== $host ? $host : $url;
    }

    /**
     * Rank-ordered, host-diverse URLs worth reading.
     *
     * @param array<int, mixed> $results
     *
     * @return array<int, string> result index => url
     */
    private function selectUrls(array $results, int $maxPages): array
    {
        $selected = [];
        $hosts = [];
        foreach ($results as $index => $result) {
            if (count($selected) >= $maxPages) {
                break;
            }
            if (!is_array($result)) {
                continue;
            }
            $url = $result['url'] ?? null;
            if (!is_string($url) || !preg_match('#^https?://#i', $url)) {
                continue;
            }
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ('' === $host || $this->isSkippedHost($host) || $this->isFileUrl($url)) {
                continue;
            }
            $registrable = $this->registrableDomain($host);
            if (isset($hosts[$registrable])) {
                continue;
            }
            $hosts[$registrable] = true;
            $selected[(int) $index] = $url;
        }

        return $selected;
    }

    private function isSkippedHost(string $host): bool
    {
        $registrable = $this->registrableDomain($host);

        return in_array($registrable, self::SKIP_HOSTS, true);
    }

    private function isFileUrl(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return '' !== $extension && in_array($extension, self::SKIP_EXTENSIONS, true);
    }

    private function registrableDomain(string $host): string
    {
        $parts = explode('.', $host);
        $count = count($parts);
        if ($count <= 2) {
            return $host;
        }
        $secondLevel = $parts[$count - 2];
        if (in_array($secondLevel, ['co', 'com', 'org', 'net', 'gov', 'edu', 'ac'], true) && 2 === strlen($parts[$count - 1])) {
            return implode('.', array_slice($parts, -3));
        }

        return implode('.', array_slice($parts, -2));
    }

    /**
     * Prompt block for pages the user linked. Blocked pages are listed with
     * their reason so the model can be honest about what it could not read.
     */
    public function formatMentionedUrlsForPrompt(ReadPagesResult $result): string
    {
        if ([] === $result->pages) {
            return '';
        }

        $sections = [];
        foreach ($result->pages as $page) {
            $header = sprintf('--- URL: %s ---', $page->url);
            if (null !== $page->finalUrl && $page->finalUrl !== $page->url) {
                $header .= sprintf("\nResolved to: %s", $page->finalUrl);
            }
            if ($page->success && isset($result->fittedText[$page->url])) {
                $body = $header;
                if ('' !== $page->title) {
                    $body .= sprintf("\nTitle: %s", $page->title);
                }
                $body .= sprintf("\nContent:\n%s", $result->fittedText[$page->url]);
                $sections[] = $body;
                continue;
            }

            $why = match ($page->blockedReason) {
                'login_wall' => 'The page requires a login; only the public preview (if any) is shown.',
                'bot_blocked' => 'The site refused automated access.',
                'unsupported_content' => 'The link points to a file type that cannot be read as text.',
                'no_text' => 'The page has no readable text (it is rendered by JavaScript or empty).',
                'blocked_address' => 'The link points to a private network address and was not fetched.',
                default => sprintf('The page could not be read (%s).', $page->error ?? 'unknown error'),
            };
            $section = $header."\nStatus: NOT READ. ".$why;
            if ('' !== $page->title) {
                $section .= sprintf("\nTitle: %s", $page->title);
            }
            if ('' !== $page->extractedText) {
                $section .= sprintf("\nPublic preview:\n%s", $page->extractedText);
            }
            $sections[] = $section;
        }

        return "## Linked Pages\n"
            ."The user referred to the following link(s). The system fetched them; content below is what the page actually says (condensed to the user's question when long). "
            ."Base your answer on this content. If a page is marked NOT READ, tell the user plainly that you could not open it and why — never guess what it contains.\n\n"
            .implode("\n\n", $sections);
    }
}
