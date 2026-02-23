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

readonly class UrlContentService
{
    private const MAX_URLS_PER_MESSAGE = 3;
    private const TIMEOUT_SECONDS = 5;
    private const MAX_RESPONSE_SIZE = 5 * 1024 * 1024; // 5MB
    private const MAX_TEXT_LENGTH = 4000;
    private const USER_AGENT = 'Synaplan URL Content Fetcher/1.0';

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
        // Try to find main content areas first
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

        // Fall back to body
        if ('' === $content) {
            if (preg_match('/<body[^>]*>(.*)<\/body>/is', $html, $matches)) {
                $content = $matches[1];
            } else {
                $content = $html;
            }
        }

        // Remove script and style tags with their content
        $content = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $content) ?? $content;
        $content = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $content) ?? $content;
        $content = preg_replace('/<nav[^>]*>.*?<\/nav>/is', '', $content) ?? $content;
        $content = preg_replace('/<header[^>]*>.*?<\/header>/is', '', $content) ?? $content;
        $content = preg_replace('/<footer[^>]*>.*?<\/footer>/is', '', $content) ?? $content;

        // Strip remaining HTML tags
        $text = strip_tags($content);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function getHostname(string $url): string
    {
        $parsed = parse_url($url);

        return $parsed['host'] ?? $url;
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
