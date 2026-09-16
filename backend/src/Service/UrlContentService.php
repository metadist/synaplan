<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\File\FileHelper;
use App\Service\Security\SsrfGuard;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class UrlContentResult
{
    /**
     * @param string|null $finalUrl      URL the content was actually read from after
     *                                   redirects / shortlink interstitials (reading mode only)
     * @param string|null $blockedReason machine-readable reason when a page could not be
     *                                   read for the user (`login_wall`, `bot_blocked`,
     *                                   `unsupported_content`, …); null on success or for
     *                                   plain transport errors
     */
    public function __construct(
        public string $url,
        public string $extractedText,
        public string $title,
        public string $hostname,
        public bool $success,
        public ?string $error = null,
        public ?string $finalUrl = null,
        public ?string $blockedReason = null,
    ) {
    }

    /** Host of the final URL when known, else of the requested one. */
    public function finalHostname(): string
    {
        $host = parse_url($this->finalUrl ?? $this->url, PHP_URL_HOST);

        return is_string($host) && '' !== $host ? $host : $this->hostname;
    }
}

final readonly class UrlContentService
{
    private const MAX_URLS_PER_MESSAGE = 3;
    private const TIMEOUT_SECONDS = 5;
    private const CRAWL_TIMEOUT_SECONDS = 15;
    private const MAX_RESPONSE_SIZE = 5 * 1024 * 1024; // 5MB
    private const MAX_TEXT_LENGTH = 4000;
    private const MAX_CRAWL_TEXT_LENGTH = 50000;
    private const MAX_API_RESPONSE_LENGTH = 8000;
    private const USER_AGENT = 'SynaplanBot/1.0 (+https://synaplan.com/bot)';
    private const ROBOTS_TXT_TIMEOUT = 3;

    /** Reading mode (user-initiated, like a link preview): per-request timeout. */
    private const READ_TIMEOUT_SECONDS = 12;

    /** Reading mode: default text cap; callers pass their own budget. */
    public const DEFAULT_READ_TEXT_LENGTH = 24000;

    /** Reading mode: redirect hops followed manually (each hop is SSRF-checked). */
    private const MAX_READ_REDIRECTS = 5;

    /** Reading mode: how many URLs one call may read. */
    public const MAX_READ_URLS = 6;

    /**
     * Reading-mode User-Agent. Honest about being a bot (contact URL kept) but
     * shaped like a browser so CDN bot-filters that reject bare tokens let
     * a user's own link through.
     */
    private const READER_USER_AGENT = 'Mozilla/5.0 (compatible; SynaplanBot/1.0; +https://synaplan.com/bot)';

    /** Hosts known to serve a "you are leaving" interstitial instead of a 3xx. */
    private const SHORTLINK_INTERSTITIAL_HOSTS = ['lnkd.in', 'l.facebook.com', 'lm.facebook.com', 'l.instagram.com', 'out.reddit.com', 'exit.sc', 'href.li', 'slack-redir.net'];

    /** Response bodies that mean "log in first" even when the status is 200. */
    private const LOGIN_WALL_MARKERS = ['authwall', 'sign in to linkedin', 'join linkedin', 'log in to facebook', 'log in or sign up to view', 'please log in to continue', 'sign in to continue', 'login_required'];
    /**
     * A targeted landmark (`<main>`, `class="entry-content"`, …) that yields
     * less than this is treated as a failed extract and we fall back to the
     * full body — page builders often put "content" in column class names.
     */
    private const MIN_USEFUL_TEXT_CHARS = 80;

    public function __construct(
        private HttpClientInterface $httpClient,
        private SsrfGuard $ssrfGuard,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Extract http(s) URLs from a message or planner input.
     *
     * Handles the shapes a Saved Task / prompt editor actually stores:
     * bare URLs, markdown links `[label](url)`, GFM autolinks `<url>`,
     * HTML `href="url"`, and emphasis wrappers (`**url**`) from the
     * prompt markdown toolbar. Decode HTML entities first so a
     * formatter round-trip cannot hide `https://`.
     *
     * @return string[]
     */
    public function extractUrls(string $message): array
    {
        $decoded = html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $candidates = [];
        foreach ($this->extractMarkdownLinkUrls($decoded) as $url) {
            $candidates[] = $url;
        }
        foreach ($this->extractHrefUrls($decoded) as $url) {
            $candidates[] = $url;
        }
        if (preg_match_all('/https?:\/\/[^\s<>"{}|\\\\^`\[\]]+/i', $decoded, $matches)) {
            foreach ($matches[0] as $url) {
                $candidates[] = $url;
            }
        }

        $urls = [];
        foreach ($candidates as $raw) {
            $normalized = $this->normalizeExtractedUrl($raw);
            if (null !== $normalized) {
                $urls[$normalized] = $normalized;
            }
        }

        return array_values($urls);
    }

    /**
     * Markdown `[label](url)` / `![alt](url)` and GFM `<https://…>` autolinks.
     * Nested parentheses in the destination (Wikipedia `Foo_(bar)`) are kept.
     *
     * @return list<string>
     */
    private function extractMarkdownLinkUrls(string $message): array
    {
        $urls = [];
        $length = \strlen($message);
        $offset = 0;
        while ($offset < $length) {
            $pos = stripos($message, '](http', $offset);
            if (false === $pos) {
                break;
            }
            $start = $pos + 2;
            $depth = 0;
            $end = $start;
            while ($end < $length) {
                $ch = $message[$end];
                if ('(' === $ch) {
                    ++$depth;
                } elseif (')' === $ch) {
                    if (0 === $depth) {
                        break;
                    }
                    --$depth;
                } elseif (ctype_space($ch)) {
                    break;
                }
                ++$end;
            }
            $urls[] = substr($message, $start, $end - $start);
            $offset = $end + 1;
        }

        if (preg_match_all('/<(https?:\/\/[^>\s]+)>/i', $message, $auto)) {
            foreach ($auto[1] as $url) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * @return list<string>
     */
    private function extractHrefUrls(string $message): array
    {
        if (!preg_match_all('/\bhref\s*=\s*["\'](https?:\/\/[^"\']+)["\']/i', $message, $matches)) {
            return [];
        }

        return $matches[1];
    }

    /**
     * Strip trailing sentence punctuation and markdown emphasis without
     * eating a balanced closing `)` that is part of the path.
     */
    private function normalizeExtractedUrl(string $url): ?string
    {
        $url = trim($url);
        $url = rtrim($url, '.,;:!?');
        $url = rtrim($url, '*_~`');

        while (str_ends_with($url, ')')) {
            $open = substr_count($url, '(');
            $close = substr_count($url, ')');
            if ($close <= $open) {
                break;
            }
            $url = substr($url, 0, -1);
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        if (!\in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        return $url;
    }

    /**
     * Fetch content from a single URL.
     */
    public function fetch(string $url): UrlContentResult
    {
        $hostname = $this->getHostname($url);

        if ($this->isBlockedUrl($url)) {
            return new UrlContentResult(
                url: $url,
                extractedText: '',
                title: '',
                hostname: $hostname,
                success: false,
                error: 'URL points to a private/blocked address',
            );
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => self::TIMEOUT_SECONDS,
                'max_redirects' => 3,
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                    'Accept' => 'text/html,application/xhtml+xml,text/plain',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                return new UrlContentResult(
                    url: $url,
                    extractedText: '',
                    title: '',
                    hostname: $hostname,
                    success: false,
                    error: sprintf('HTTP %d', $statusCode),
                );
            }

            $content = $response->getContent();

            if (strlen($content) > self::MAX_RESPONSE_SIZE) {
                $content = substr($content, 0, self::MAX_RESPONSE_SIZE);
            }

            $title = $this->extractTitle($content);
            $text = $this->extractText($content);

            if (strlen($text) > self::MAX_TEXT_LENGTH) {
                $text = mb_substr($text, 0, self::MAX_TEXT_LENGTH).'...';
            }

            $this->logger->info('URL content fetched successfully', [
                'url' => FileHelper::redactUrlForLogging($url),
                'text_length' => strlen($text),
                'title' => $title,
            ]);

            return new UrlContentResult(
                url: $url,
                extractedText: $text,
                title: $title,
                hostname: $hostname,
                success: true,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to fetch URL content', [
                'url' => FileHelper::redactUrlForLogging($url),
                'error' => $e->getMessage(),
            ]);

            return new UrlContentResult(
                url: $url,
                extractedText: '',
                title: '',
                hostname: $hostname,
                success: false,
                error: $e->getMessage(),
            );
        }
    }

    /**
     * Fetch content from multiple URLs.
     *
     * @param string[] $urls
     *
     * @return UrlContentResult[]
     */
    public function fetchMultiple(array $urls): array
    {
        $urls = array_slice($urls, 0, self::MAX_URLS_PER_MESSAGE);
        $results = [];

        foreach ($urls as $url) {
            $results[] = $this->fetch($url);
        }

        return $results;
    }

    /**
     * Read a page ON BEHALF OF THE USER — the mode for links a user pasted
     * into the chat or that a web search returned for their question.
     *
     * Differences from {@see fetch()} / {@see fetchForCrawling()}:
     *  - redirects are followed hop by hop, and EVERY hop is SSRF-checked
     *    (the HTTP client's own redirect following would let a public
     *    shortlink bounce us to 169.254.169.254);
     *  - shortlink interstitials without a 3xx (lnkd.in, l.facebook.com, …)
     *    and `<meta http-equiv="refresh">` pages are resolved to their
     *    target, so the user's LinkedIn share link yields the article, not
     *    "This link will take you to a page that's not on LinkedIn";
     *  - robots.txt / noindex are NOT consulted: this is a one-off read the
     *    user asked for, like their browser's link preview — not indexing;
     *  - login walls and bot blocks are reported as `blockedReason` so the
     *    answering model can say "this page needs a login" instead of
     *    guessing the content;
     *  - the text cap is the caller's budget (default 24 000 chars), large
     *    enough for a full article to be condensed downstream.
     */
    public function fetchForReading(string $url, int $maxChars = self::DEFAULT_READ_TEXT_LENGTH, ?ResponseInterface $firstHop = null): UrlContentResult
    {
        $hostname = $this->getHostname($url);
        $current = $url;
        $visited = [];

        for ($hop = 0; $hop <= self::MAX_READ_REDIRECTS; ++$hop) {
            if ($this->isBlockedUrl($current)) {
                return $this->readFailure($url, $hostname, $current, 'URL points to a private/blocked address', 'blocked_address');
            }
            if (isset($visited[$current])) {
                return $this->readFailure($url, $hostname, $current, 'Redirect loop', 'redirect_loop');
            }
            $visited[$current] = true;

            try {
                // A caller that reads several pages starts every first hop up
                // front (see startReading()) so the transfers overlap instead
                // of queueing behind each other's timeout.
                $response = 0 === $hop && null !== $firstHop
                    ? $firstHop
                    : $this->startReadingRequest($current);

                $statusCode = $response->getStatusCode();
                $headers = $response->getHeaders(false);

                if ($statusCode >= 300 && $statusCode < 400) {
                    $location = $headers['location'][0] ?? null;
                    if (null === $location || '' === $location) {
                        return $this->readFailure($url, $hostname, $current, sprintf('HTTP %d without Location', $statusCode), 'bad_redirect');
                    }
                    $current = $this->resolveRelativeUrl($current, $location);
                    continue;
                }

                if (999 === $statusCode || 401 === $statusCode || 403 === $statusCode || 429 === $statusCode) {
                    $reason = 999 === $statusCode || 403 === $statusCode || 429 === $statusCode ? 'bot_blocked' : 'login_wall';

                    return $this->readFailure($url, $hostname, $current, sprintf('HTTP %d', $statusCode), $reason);
                }

                if ($statusCode >= 400) {
                    return $this->readFailure($url, $hostname, $current, sprintf('HTTP %d', $statusCode), null);
                }

                $contentType = strtolower($headers['content-type'][0] ?? '');
                $content = $response->getContent(false);
                if (strlen($content) > self::MAX_RESPONSE_SIZE) {
                    $content = substr($content, 0, self::MAX_RESPONSE_SIZE);
                }

                if (str_contains($contentType, 'application/json') || str_starts_with($contentType, 'text/plain')) {
                    return $this->readSuccess($url, $hostname, $current, $this->getHostname($current), $this->capText(trim($content), $maxChars));
                }

                if ('' !== $contentType && !str_contains($contentType, 'html') && !str_contains($contentType, 'xml')) {
                    return $this->readFailure($url, $hostname, $current, sprintf('Unsupported content type %s', $contentType), 'unsupported_content');
                }

                // Interstitial / meta-refresh → one more hop to the real target.
                $target = $this->resolveInterstitialTarget($current, $content);
                if (null !== $target && $target !== $current && $hop < self::MAX_READ_REDIRECTS) {
                    $this->logger->info('URL reading: following interstitial', [
                        'from' => FileHelper::redactUrlForLogging($current),
                        'to' => FileHelper::redactUrlForLogging($target),
                    ]);
                    $current = $target;
                    continue;
                }

                $title = $this->extractTitle($content);
                $metaDescription = $this->extractMetaDescription($content);
                $bodyText = $this->extractTextForCrawl($content);

                if ($this->looksLikeLoginWall($current, $content, $bodyText)) {
                    $preview = '' !== $metaDescription ? $metaDescription : mb_substr($bodyText, 0, 300);

                    return new UrlContentResult(
                        url: $url,
                        extractedText: $preview,
                        title: $title,
                        hostname: $hostname,
                        success: false,
                        error: 'Page requires a login',
                        finalUrl: $current,
                        blockedReason: 'login_wall',
                    );
                }

                $parts = [];
                if ('' !== $metaDescription && !str_contains($bodyText, $metaDescription)) {
                    $parts[] = $metaDescription;
                }
                if ('' !== $bodyText) {
                    $parts[] = $bodyText;
                }
                $text = $this->capText(implode("\n\n", $parts), $maxChars);

                if ('' === trim($text)) {
                    return $this->readFailure($url, $hostname, $current, 'Page has no readable text (client-rendered or empty)', 'no_text');
                }

                $this->logger->info('URL content read successfully', [
                    'url' => FileHelper::redactUrlForLogging($url),
                    'final_url' => FileHelper::redactUrlForLogging($current),
                    'hops' => $hop,
                    'text_length' => strlen($text),
                ]);

                return $this->readSuccess($url, $hostname, $current, $title, $text);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to read URL content', [
                    'url' => FileHelper::redactUrlForLogging($current),
                    'error' => $e->getMessage(),
                ]);

                return $this->readFailure($url, $hostname, $current, $e->getMessage(), null);
            }
        }

        return $this->readFailure($url, $hostname, $current, 'Too many redirects', 'redirect_loop');
    }

    /**
     * Start the first hop of a page read without consuming it.
     *
     * Symfony's HTTP client runs every started request concurrently in the
     * background; reading three result pages sequentially therefore costs the
     * slowest page, not the sum. Pass the response to {@see fetchForReading()}.
     * Blocked addresses are not started — fetchForReading() rejects them first.
     */
    public function startReading(string $url): ?ResponseInterface
    {
        if ($this->isBlockedUrl($url)) {
            return null;
        }

        try {
            return $this->startReadingRequest($url);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to start URL read', [
                'url' => FileHelper::redactUrlForLogging($url),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function startReadingRequest(string $url): ResponseInterface
    {
        return $this->httpClient->request('GET', $url, [
            'timeout' => self::READ_TIMEOUT_SECONDS,
            'max_redirects' => 0,
            'headers' => [
                'User-Agent' => self::READER_USER_AGENT,
                'Accept' => 'text/html,application/xhtml+xml,application/json,text/plain;q=0.9,*/*;q=0.5',
                'Accept-Language' => 'en,de;q=0.8,*;q=0.5',
            ],
        ]);
    }

    /**
     * Read several pages for the user; order of results follows `$urls`.
     *
     * @param string[] $urls
     *
     * @return UrlContentResult[]
     */
    public function fetchManyForReading(array $urls, int $maxCharsPerPage = self::DEFAULT_READ_TEXT_LENGTH, int $limit = self::MAX_READ_URLS): array
    {
        $unique = array_values(array_unique(array_filter($urls, static fn (string $u): bool => '' !== trim($u))));
        $results = [];
        foreach (array_slice($unique, 0, max(1, $limit)) as $url) {
            $results[] = $this->fetchForReading($url, $maxCharsPerPage);
        }

        return $results;
    }

    /**
     * Target of a shortlink interstitial or `<meta http-equiv="refresh">`, or
     * null when the page is a regular document.
     */
    private function resolveInterstitialTarget(string $currentUrl, string $html): ?string
    {
        if (preg_match('/<meta[^>]+http-equiv=["\']refresh["\'][^>]+content=["\']\s*\d+\s*;\s*url\s*=\s*([^"\'>\s]+)/i', $html, $m)) {
            return $this->sanitizeTarget($currentUrl, html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $host = strtolower($this->getHostname($currentUrl));
        $isKnownInterstitial = in_array($host, self::SHORTLINK_INTERSTITIAL_HOSTS, true)
            || (str_starts_with($host, 'www.') && in_array(substr($host, 4), self::SHORTLINK_INTERSTITIAL_HOSTS, true));
        if (!$isKnownInterstitial) {
            return null;
        }

        // LinkedIn puts the destination in the redirect-notice anchor; other
        // interstitials use a `u=` / `url=` / `target=` query parameter.
        $query = parse_url($currentUrl, PHP_URL_QUERY);
        if (is_string($query) && '' !== $query) {
            parse_str($query, $params);
            foreach (['url', 'u', 'target', 'dest', 'to', 'q'] as $key) {
                if (isset($params[$key]) && is_string($params[$key]) && str_starts_with($params[$key], 'http')) {
                    return $this->sanitizeTarget($currentUrl, $params[$key]);
                }
            }
        }

        if (preg_match_all('/href=["\'](https?:\/\/[^"\']+)["\']/i', $html, $links)) {
            $registrable = $this->registrableDomain($host);
            foreach ($links[1] as $candidate) {
                $decoded = html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $candidateHost = strtolower($this->getHostname($decoded));
                if ('' === $candidateHost || $this->registrableDomain($candidateHost) === $registrable) {
                    continue;
                }
                if (in_array($this->registrableDomain($candidateHost), ['linkedin.com', 'licdn.com', 'facebook.com', 'fbcdn.net', 'instagram.com', 'reddit.com', 'redditstatic.com', 'slack.com'], true)) {
                    continue;
                }

                return $this->sanitizeTarget($currentUrl, $decoded);
            }
        }

        return null;
    }

    private function sanitizeTarget(string $base, string $target): ?string
    {
        $target = trim($target);
        if ('' === $target) {
            return null;
        }
        $absolute = $this->resolveRelativeUrl($base, $target);
        $scheme = strtolower((string) parse_url($absolute, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $absolute : null;
    }

    private function resolveRelativeUrl(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parsed = parse_url($base);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';
        $origin = $scheme.'://'.$host.$port;

        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $path = $parsed['path'] ?? '/';
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $origin.$dir.$location;
    }

    private function registrableDomain(string $host): string
    {
        $parts = explode('.', strtolower($host));
        $count = count($parts);
        if ($count <= 2) {
            return strtolower($host);
        }

        // Two-level public suffixes (co.uk, com.au, …): keep three labels.
        $secondLevel = $parts[$count - 2];
        if (in_array($secondLevel, ['co', 'com', 'org', 'net', 'gov', 'edu', 'ac'], true) && 2 === strlen($parts[$count - 1])) {
            return implode('.', array_slice($parts, -3));
        }

        return implode('.', array_slice($parts, -2));
    }

    private function looksLikeLoginWall(string $url, string $html, string $bodyText): bool
    {
        if (str_contains(strtolower($url), '/authwall') || str_contains(strtolower($url), '/login')) {
            return true;
        }

        $haystack = strtolower(mb_substr($bodyText, 0, 2000)."\n".mb_substr($html, 0, 4000));
        foreach (self::LOGIN_WALL_MARKERS as $marker) {
            if (str_contains($haystack, $marker)) {
                // Real articles mention "sign in" in their chrome too — only
                // call it a wall when there is hardly any body text.
                return mb_strlen($bodyText) < 1200;
            }
        }

        return false;
    }

    private function capText(string $text, int $maxChars): string
    {
        $maxChars = max(200, $maxChars);
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return mb_substr($text, 0, $maxChars).'...';
    }

    private function readSuccess(string $url, string $hostname, string $finalUrl, string $title, string $text): UrlContentResult
    {
        return new UrlContentResult(
            url: $url,
            extractedText: $text,
            title: $title,
            hostname: $hostname,
            success: true,
            finalUrl: $finalUrl,
        );
    }

    private function readFailure(string $url, string $hostname, string $finalUrl, string $error, ?string $blockedReason): UrlContentResult
    {
        return new UrlContentResult(
            url: $url,
            extractedText: '',
            title: '',
            hostname: $hostname,
            success: false,
            error: $error,
            finalUrl: $finalUrl,
            blockedReason: $blockedReason,
        );
    }

    /**
     * Fetch a page with higher limits and richer extraction, optimized for RAG vectorization.
     * Respects robots.txt and noindex meta tags for GDPR/crawl compliance.
     */
    public function fetchForCrawling(string $url): UrlContentResult
    {
        $hostname = $this->getHostname($url);

        if ($this->isBlockedUrl($url)) {
            return new UrlContentResult(
                url: $url,
                extractedText: '',
                title: '',
                hostname: $hostname,
                success: false,
                error: 'URL points to a private/blocked address',
            );
        }

        if (!$this->isAllowedByRobotsTxt($url)) {
            $this->logger->info('Crawl blocked by robots.txt', ['url' => FileHelper::redactUrlForLogging($url)]);

            return new UrlContentResult(
                url: $url,
                extractedText: '',
                title: '',
                hostname: $hostname,
                success: false,
                error: 'Blocked by robots.txt',
            );
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => self::CRAWL_TIMEOUT_SECONDS,
                'max_redirects' => 5,
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                    'Accept' => 'text/html,application/xhtml+xml,application/json,text/plain',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                return new UrlContentResult(
                    url: $url,
                    extractedText: '',
                    title: '',
                    hostname: $hostname,
                    success: false,
                    error: sprintf('HTTP %d', $statusCode),
                );
            }

            $headers = $response->getHeaders();

            if ($this->hasNoIndexHeader($headers)) {
                $this->logger->info('Crawl skipped: X-Robots-Tag noindex', ['url' => FileHelper::redactUrlForLogging($url)]);

                return new UrlContentResult(
                    url: $url,
                    extractedText: '',
                    title: '',
                    hostname: $hostname,
                    success: false,
                    error: 'Blocked by X-Robots-Tag noindex header',
                );
            }

            $content = $response->getContent();

            if (strlen($content) > self::MAX_RESPONSE_SIZE) {
                $content = substr($content, 0, self::MAX_RESPONSE_SIZE);
            }

            $contentType = $headers['content-type'][0] ?? '';

            if (str_contains($contentType, 'application/json')) {
                $title = $hostname;
                $text = $content;
                if (strlen($text) > self::MAX_CRAWL_TEXT_LENGTH) {
                    $text = mb_substr($text, 0, self::MAX_CRAWL_TEXT_LENGTH).'...';
                }

                return new UrlContentResult(
                    url: $url,
                    extractedText: $text,
                    title: $title,
                    hostname: $hostname,
                    success: true,
                );
            }

            if ($this->hasNoIndexMeta($content)) {
                $this->logger->info('Crawl skipped: page has noindex meta tag', ['url' => FileHelper::redactUrlForLogging($url)]);

                return new UrlContentResult(
                    url: $url,
                    extractedText: '',
                    title: '',
                    hostname: $hostname,
                    success: false,
                    error: 'Page has noindex meta tag',
                );
            }

            $title = $this->extractTitle($content);
            $metaDescription = $this->extractMetaDescription($content);
            $jsonLd = $this->extractJsonLd($content);
            $bodyText = $this->extractTextForCrawl($content);

            $parts = [];
            if ('' !== $metaDescription) {
                $parts[] = $metaDescription;
            }
            if ('' !== $jsonLd) {
                $parts[] = $jsonLd;
            }
            if ('' !== $bodyText) {
                $parts[] = $bodyText;
            }

            $text = implode("\n\n", $parts);

            if (strlen($text) > self::MAX_CRAWL_TEXT_LENGTH) {
                $text = mb_substr($text, 0, self::MAX_CRAWL_TEXT_LENGTH).'...';
            }

            $this->logger->info('URL content crawled successfully', [
                'url' => FileHelper::redactUrlForLogging($url),
                'text_length' => strlen($text),
                'title' => $title,
            ]);

            return new UrlContentResult(
                url: $url,
                extractedText: $text,
                title: $title,
                hostname: $hostname,
                success: true,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to crawl URL content', [
                'url' => FileHelper::redactUrlForLogging($url),
                'error' => $e->getMessage(),
            ]);

            return new UrlContentResult(
                url: $url,
                extractedText: '',
                title: '',
                hostname: $hostname,
                success: false,
                error: $e->getMessage(),
            );
        }
    }

    /**
     * Fetch an API endpoint with a tighter response limit to control token usage.
     * Does NOT check robots.txt (APIs are intended to be consumed programmatically).
     */
    /**
     * @param array<string, string> $headers
     */
    public function fetchApi(string $url, string $method = 'GET', array $headers = []): UrlContentResult
    {
        $hostname = $this->getHostname($url);

        if ($this->isBlockedUrl($url)) {
            return new UrlContentResult(
                url: $url,
                extractedText: '',
                title: '',
                hostname: $hostname,
                success: false,
                error: 'URL points to a private/blocked address',
            );
        }

        try {
            $response = $this->httpClient->request($method, $url, [
                'timeout' => self::TIMEOUT_SECONDS,
                'max_redirects' => 3,
                'headers' => array_merge([
                    'User-Agent' => self::USER_AGENT,
                    'Accept' => 'application/json,text/plain',
                ], $headers),
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                return new UrlContentResult(
                    url: $url,
                    extractedText: '',
                    title: '',
                    hostname: $hostname,
                    success: false,
                    error: sprintf('HTTP %d', $statusCode),
                );
            }

            $text = $response->getContent();

            if (strlen($text) > self::MAX_API_RESPONSE_LENGTH) {
                $text = mb_substr($text, 0, self::MAX_API_RESPONSE_LENGTH).'... [truncated]';
            }

            return new UrlContentResult(
                url: $url,
                extractedText: $text,
                title: $hostname,
                hostname: $hostname,
                success: true,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('API fetch failed', [
                'url' => FileHelper::redactUrlForLogging($url),
                'method' => $method,
                'error' => $e->getMessage(),
            ]);

            return new UrlContentResult(
                url: $url,
                extractedText: '',
                title: '',
                hostname: $hostname,
                success: false,
                error: $e->getMessage(),
            );
        }
    }

    /**
     * Format URL content results for injection into AI prompt context.
     *
     * @param UrlContentResult[] $results
     */
    public function formatForPrompt(array $results): string
    {
        $successfulResults = array_filter($results, static fn (UrlContentResult $r): bool => $r->success && '' !== $r->extractedText);

        if (empty($successfulResults)) {
            return '';
        }

        $sections = [];
        foreach ($successfulResults as $result) {
            $section = sprintf("--- URL: %s ---\n", $result->url);
            if (null !== $result->finalUrl && $result->finalUrl !== $result->url) {
                $section .= sprintf("Resolved to: %s\n", $result->finalUrl);
            }
            if ('' !== $result->title) {
                $section .= sprintf("Title: %s\n", $result->title);
            }
            $section .= sprintf("Content:\n%s", $result->extractedText);
            $sections[] = $section;
        }

        return "## URL Content\nThe following content was extracted from URLs mentioned by the user:\n\n".implode("\n\n", $sections);
    }

    private function extractTitle(string $html): string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            return trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return '';
    }

    private function extractText(string $html): string
    {
        return $this->extractTextWithLimit($html, false);
    }

    /**
     * Richer text extraction for crawling: keeps more content, strips SVG/noise.
     */
    private function extractTextForCrawl(string $html): string
    {
        return $this->extractTextWithLimit($html, true);
    }

    private function extractTextWithLimit(string $html, bool $fullMode): string
    {
        $targeted = $this->selectTargetedHtml($html);
        $body = $this->selectBodyHtml($html);

        $candidates = [];
        if ('' !== $targeted) {
            // Already scoped to a landmark — keep inner <header>/<nav>
            // (article H1s often live in <header>).
            $candidates[] = $this->htmlToPlainText($targeted, false);
        }
        $candidates[] = $this->htmlToPlainText($body, !$fullMode);
        if (!$fullMode) {
            // Last resort: keep nav/header/footer when chrome-stripping
            // left almost nothing (JS builders often skip those tags).
            $candidates[] = $this->htmlToPlainText($body, false);
        }

        $best = '';
        foreach ($candidates as $text) {
            if ($this->isUsefulText($text)) {
                return $text;
            }
            if (mb_strlen($text) > mb_strlen($best)) {
                $best = $text;
            }
        }

        return $best;
    }

    /**
     * Prefer semantic landmarks. Avoid substring matches like Kubio's
     * `h-column__content`, which previously ate the whole extract.
     */
    private function selectTargetedHtml(string $html): string
    {
        $patterns = [
            '/<main[^>]*>(.*?)<\/main>/is',
            '/<article[^>]*>(.*?)<\/article>/is',
            '/<(?:div|section)[^>]+role=["\']main["\'][^>]*>(.*?)<\/(?:div|section)>/is',
            '/<(?:div|section)[^>]+id=["\'](?:content|main|primary)["\'][^>]*>(.*?)<\/(?:div|section)>/is',
            '/<(?:div|section)[^>]+class=["\'](?:[^"\']*\s)?(?:entry-content|post-content|page-content|site-content|main-content|article-content|wp-block-post-content)(?:\s[^"\']*)?["\'][^>]*>(.*?)<\/(?:div|section)>/is',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                return $matches[1];
            }
        }

        return '';
    }

    private function selectBodyHtml(string $html): string
    {
        if (preg_match('/<body[^>]*>(.*)<\/body>/is', $html, $matches)) {
            return $matches[1];
        }

        return $html;
    }

    private function htmlToPlainText(string $content, bool $stripChrome): string
    {
        $content = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $content) ?? $content;
        $content = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $content) ?? $content;
        $content = preg_replace('/<svg[^>]*>.*?<\/svg>/is', '', $content) ?? $content;
        $content = preg_replace('/<noscript[^>]*>.*?<\/noscript>/is', '', $content) ?? $content;

        if ($stripChrome) {
            $content = preg_replace('/<nav[^>]*>.*?<\/nav>/is', '', $content) ?? $content;
            $content = preg_replace('/<header[^>]*>.*?<\/header>/is', '', $content) ?? $content;
            $content = preg_replace('/<footer[^>]*>.*?<\/footer>/is', '', $content) ?? $content;
        }

        $content = preg_replace('/<\/(div|p|h[1-6]|li|section|article|header|footer|nav|main|blockquote|tr|td|th|dt|dd|figcaption|details|summary)>/i', "</$1>\n", $content) ?? $content;
        $content = preg_replace('/<(br|hr)\s*\/?>/i', "\n", $content) ?? $content;

        $text = strip_tags($content);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        // Trim every line, then collapse runs of blank lines. A single
        // non-overlapping `\n[ \t]*\n` pass leaves every second blank line
        // alive, which on chrome-heavy pages (social feeds, menus) wasted
        // most of the reading budget on whitespace.
        $text = preg_replace('/[ \t]*\n[ \t]*/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function isUsefulText(string $text): bool
    {
        return mb_strlen($text) >= self::MIN_USEFUL_TEXT_CHARS;
    }

    private function extractMetaDescription(string $html): string
    {
        if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/is', $html, $matches)) {
            return trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        if (preg_match('/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\']/is', $html, $matches)) {
            return trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return '';
    }

    /**
     * Extract JSON-LD structured data (schema.org) into readable text.
     */
    private function extractJsonLd(string $html): string
    {
        if (!preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            return '';
        }

        $parts = [];
        foreach ($matches[1] as $json) {
            try {
                $data = json_decode(trim($json), true, 32, JSON_THROW_ON_ERROR);
                if (!\is_array($data)) {
                    continue;
                }

                $flat = $this->flattenJsonLd($data);
                if ('' !== $flat) {
                    $parts[] = $flat;
                }
            } catch (\JsonException) {
                // ignore malformed JSON-LD
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function flattenJsonLd(array $data, string $prefix = ''): string
    {
        $lines = [];
        foreach ($data as $key => $value) {
            if (str_starts_with((string) $key, '@') && '@type' !== $key) {
                continue;
            }

            $label = '' !== $prefix ? "{$prefix}.{$key}" : (string) $key;

            if (\is_string($value) && '' !== $value) {
                $lines[] = "{$label}: {$value}";
            } elseif (\is_array($value)) {
                if (isset($value[0]) && \is_string($value[0])) {
                    $lines[] = "{$label}: ".implode(', ', $value);
                } elseif (isset($value[0]) && \is_array($value[0])) {
                    foreach ($value as $i => $item) {
                        if (\is_array($item)) {
                            $nested = $this->flattenJsonLd($item, "{$label}[{$i}]");
                            if ('' !== $nested) {
                                $lines[] = $nested;
                            }
                        }
                    }
                } else {
                    $nested = $this->flattenJsonLd($value, $label);
                    if ('' !== $nested) {
                        $lines[] = $nested;
                    }
                }
            }
        }

        return implode("\n", $lines);
    }

    private function getHostname(string $url): string
    {
        $parsed = parse_url($url);

        return $parsed['host'] ?? $url;
    }

    /**
     * Check if our bot is allowed to crawl this URL according to the site's robots.txt.
     * Uses a simple parser that handles User-agent and Disallow directives.
     */
    private function isAllowedByRobotsTxt(string $url): bool
    {
        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '/';

        if ('' === $host) {
            return true;
        }

        $robotsUrl = "{$scheme}://{$host}/robots.txt";

        try {
            $response = $this->httpClient->request('GET', $robotsUrl, [
                'timeout' => self::ROBOTS_TXT_TIMEOUT,
                'max_redirects' => 2,
                'headers' => ['User-Agent' => self::USER_AGENT],
            ]);

            if ($response->getStatusCode() >= 400) {
                return true;
            }

            $robotsTxt = $response->getContent();

            return $this->parseRobotsTxt($robotsTxt, $path);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Minimal robots.txt parser: checks SynaplanBot and wildcard (*) user-agent sections.
     */
    private function parseRobotsTxt(string $content, string $path): bool
    {
        $lines = preg_split('/\r?\n/', $content) ?: [];
        $activeSection = false;
        $disallowRules = [];

        foreach ($lines as $line) {
            $line = trim(explode('#', $line, 2)[0]);
            if ('' === $line) {
                continue;
            }

            if (preg_match('/^User-agent:\s*(.+)/i', $line, $m)) {
                $agent = trim($m[1]);
                $agentLower = strtolower($agent);
                $activeSection = '*' === $agent
                    || str_contains($agentLower, 'synaplan')
                    || str_contains($agentLower, 'synaplanbot');
                continue;
            }

            if ($activeSection && preg_match('/^Disallow:\s*(.*)/i', $line, $m)) {
                $rule = trim($m[1]);
                if ('' !== $rule) {
                    $disallowRules[] = $rule;
                }
            }
        }

        foreach ($disallowRules as $rule) {
            if ('/' === $rule) {
                return false;
            }
            if (str_starts_with($path, $rule)) {
                return false;
            }
        }

        return true;
    }

    private function hasNoIndexMeta(string $html): bool
    {
        return (bool) preg_match('/<meta[^>]+name=["\']robots["\'][^>]+content=["\'][^"\']*noindex[^"\']*["\']/is', $html);
    }

    /**
     * @param array<string, string[]> $headers
     */
    private function hasNoIndexHeader(array $headers): bool
    {
        $values = $headers['x-robots-tag'] ?? [];
        foreach ($values as $value) {
            if (str_contains(strtolower($value), 'noindex')) {
                return true;
            }
        }

        return false;
    }

    /**
     * SSRF protection is delegated to the shared {@see SsrfGuard} so the URL
     * node, the outbound MCP client and this enrichment service enforce ONE
     * identical policy (release-4.0 plan 09 §2.5).
     */
    private function isBlockedUrl(string $url): bool
    {
        return $this->ssrfGuard->isBlockedUrl($url);
    }
}
