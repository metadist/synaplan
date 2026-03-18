<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class UrlContentResult
{
    public function __construct(
        public string $url,
        public string $extractedText,
        public string $title,
        public string $hostname,
        public bool $success,
        public ?string $error = null,
    ) {
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

    /** @var string[] */
    private const BLOCKED_IP_RANGES = [
        '127.',
        '10.',
        '0.',
        '169.254.',
        '::1',
        'localhost',
    ];

    /** @var string[] */
    private const BLOCKED_CIDR_PREFIXES = [
        '172.16.', '172.17.', '172.18.', '172.19.',
        '172.20.', '172.21.', '172.22.', '172.23.',
        '172.24.', '172.25.', '172.26.', '172.27.',
        '172.28.', '172.29.', '172.30.', '172.31.',
        '192.168.',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Extract URLs from a message text.
     *
     * @return string[]
     */
    public function extractUrls(string $message): array
    {
        preg_match_all(
            '/https?:\/\/[^\s<>"{}|\\\\^`\[\]]+/i',
            $message,
            $matches
        );

        $urls = array_unique($matches[0]);

        // Clean trailing punctuation that's likely not part of the URL
        return array_values(array_map(static fn (string $url): string => rtrim($url, '.,;:!?)'), $urls));
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
                'url' => $url,
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
                'url' => $url,
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
            $this->logger->info('Crawl blocked by robots.txt', ['url' => $url]);

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
                $this->logger->info('Crawl skipped: X-Robots-Tag noindex', ['url' => $url]);

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
                $this->logger->info('Crawl skipped: page has noindex meta tag', ['url' => $url]);

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
                'url' => $url,
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
                'url' => $url,
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
                'url' => $url,
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
        $contentPatterns = [
            '/<main[^>]*>(.*?)<\/main>/is',
            '/<article[^>]*>(.*?)<\/article>/is',
            '/<div[^>]*(?:role=["\']main["\']|id=["\']content["\']|class=["\'][^"\']*content[^"\']*["\'])[^>]*>(.*?)<\/div>/is',
        ];

        $content = '';
        foreach ($contentPatterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $content = $matches[1];
                break;
            }
        }

        if ('' === $content) {
            if (preg_match('/<body[^>]*>(.*)<\/body>/is', $html, $matches)) {
                $content = $matches[1];
            } else {
                $content = $html;
            }
        }

        $content = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $content) ?? $content;
        $content = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $content) ?? $content;
        $content = preg_replace('/<svg[^>]*>.*?<\/svg>/is', '', $content) ?? $content;
        $content = preg_replace('/<noscript[^>]*>.*?<\/noscript>/is', '', $content) ?? $content;

        if (!$fullMode) {
            $content = preg_replace('/<nav[^>]*>.*?<\/nav>/is', '', $content) ?? $content;
            $content = preg_replace('/<header[^>]*>.*?<\/header>/is', '', $content) ?? $content;
            $content = preg_replace('/<footer[^>]*>.*?<\/footer>/is', '', $content) ?? $content;
        }

        // Insert newlines before block-level elements to prevent word concatenation
        $content = preg_replace('/<\/(div|p|h[1-6]|li|section|article|header|footer|nav|main|blockquote|tr|td|th|dt|dd|figcaption|details|summary)>/i', "</$1>\n", $content) ?? $content;
        $content = preg_replace('/<(br|hr)\s*\/?>/i', "\n", $content) ?? $content;

        $text = strip_tags($content);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n[ \t]*\n/', "\n\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
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

    private function isBlockedUrl(string $url): bool
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';

        if ('' === $host) {
            return true;
        }

        $hostLower = strtolower($host);

        foreach (self::BLOCKED_IP_RANGES as $range) {
            if (str_starts_with($hostLower, strtolower($range)) || $hostLower === strtolower($range)) {
                return true;
            }
        }

        foreach (self::BLOCKED_CIDR_PREFIXES as $prefix) {
            if (str_starts_with($host, $prefix)) {
                return true;
            }
        }

        // Resolve hostname to check for private IPs
        $ip = gethostbyname($host);
        if ($ip !== $host) {
            foreach (self::BLOCKED_IP_RANGES as $range) {
                if (str_starts_with($ip, $range)) {
                    return true;
                }
            }
            foreach (self::BLOCKED_CIDR_PREFIXES as $prefix) {
                if (str_starts_with($ip, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }
}
