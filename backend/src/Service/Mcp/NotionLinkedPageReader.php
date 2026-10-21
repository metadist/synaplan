<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Entity\McpServerConfig;
use App\Repository\McpServerConfigRepository;
use Psr\Log\LoggerInterface;

/**
 * Reads a pasted Notion link through the user's Notion connection.
 *
 * A public fetch of notion.so only sees the sign-in screen. When Notion is
 * not connected, the link is still claimed so the public reader never runs,
 * and the answer says to connect Notion under Apps.
 */
final readonly class NotionLinkedPageReader
{
    private const MAX_PAGE_CHARS = 12000;

    private const CONNECT = 'Connect Notion under Apps to read this page. Nothing was read from the public web.';

    private const NOT_ALLOWED = 'This chat cannot use your Notion connection, so the page was not read.';

    public function __construct(
        private McpServerConfigRepository $servers,
        private McpClient $client,
        private McpClientConfig $clientConfig,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string>   $urls
     * @param list<int>|null $serverAllowlist null = every enabled connection; a list limits which connection may be called
     * @param callable|null  $onReady         fired once a page and an allowed connection are known, before the first remote call
     */
    public function read(array $urls, int $userId, ?array $serverAllowlist = null, ?callable $onReady = null, bool $topicAllowsMcp = true): NotionLinkedPageRead
    {
        $pages = $this->notionPages($urls);
        if ([] === $pages) {
            return new NotionLinkedPageRead([]);
        }
        if ($userId <= 0 || !$this->clientConfig->isClientEnabled($userId)) {
            return new NotionLinkedPageRead($this->failures($pages, 'Notion', self::CONNECT));
        }

        $enabled = $this->servers->findEnabledByUser($userId);
        $any = $this->pick($enabled, null);
        $allowed = $topicAllowsMcp ? $this->pick($enabled, $serverAllowlist) : null;
        if (null === $allowed) {
            $text = null === $any ? self::CONNECT : self::NOT_ALLOWED;

            return new NotionLinkedPageRead($this->failures($pages, 'Notion', $text));
        }

        if (null !== $onReady) {
            $onReady();
        }

        try {
            $tools = $this->client->listTools($allowed);
        } catch (McpClientException $e) {
            $this->logger->warning('NotionLinkedPageReader: tool list failed', [
                'server_id' => $allowed->getId(),
                'error' => $e->getMessage(),
            ]);

            return new NotionLinkedPageRead($this->failures($pages, $allowed->getName(), $this->plainError($e->getMessage())));
        }

        $tool = $this->fetchTool($tools);
        if (null === $tool) {
            return new NotionLinkedPageRead($this->failures(
                $pages,
                $allowed->getName(),
                'This connection does not offer a tool that can open a Notion page.',
            ));
        }

        $read = [];
        foreach ($pages as $page) {
            $read[] = $this->readOne($allowed, $tool, $page);
        }

        return new NotionLinkedPageRead($read);
    }

    /**
     * @param list<string> $urls
     *
     * @return list<array{url: string}>
     */
    private function notionPages(array $urls): array
    {
        $pages = [];
        foreach ($urls as $url) {
            if ($this->isNotionUrl($url)) {
                $pages[] = ['url' => $url];
            }
        }

        return $pages;
    }

    private function isNotionUrl(string $url): bool
    {
        if (!str_starts_with(strtolower($url), 'https://')) {
            return false;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return 'notion.so' === $host
            || 'notion.com' === $host
            || 'notion.site' === $host
            || str_ends_with($host, '.notion.so')
            || str_ends_with($host, '.notion.site');
    }

    /**
     * @param list<McpServerConfig> $servers
     * @param list<int>|null        $allowlist
     */
    private function pick(array $servers, ?array $allowlist): ?McpServerConfig
    {
        foreach ($servers as $server) {
            if (null !== $allowlist && !in_array((int) $server->getId(), $allowlist, true)) {
                continue;
            }
            $host = strtolower((string) parse_url($server->getUrl(), PHP_URL_HOST));
            if ('mcp.notion.com' === $host) {
                return $server;
            }
        }

        return null;
    }

    /**
     * @param list<array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>}> $tools
     *
     * @return array{name: string, inputSchema: array<string, mixed>}|null
     */
    private function fetchTool(array $tools): ?array
    {
        foreach ($tools as $tool) {
            $name = strtolower($tool['name']);
            $bare = str_starts_with($name, 'notion-') ? substr($name, strlen('notion-')) : $name;
            if ('fetch' === $bare) {
                return ['name' => $tool['name'], 'inputSchema' => $tool['inputSchema']];
            }
        }

        return null;
    }

    /**
     * @param array{name: string, inputSchema: array<string, mixed>} $tool
     * @param array{url: string}                                     $page
     *
     * @return array{url: string, success: bool, serverName: string, text: string}
     */
    private function readOne(McpServerConfig $server, array $tool, array $page): array
    {
        $properties = is_array($tool['inputSchema']['properties'] ?? null) ? $tool['inputSchema']['properties'] : [];
        $argument = isset($properties['url']) && !isset($properties['id']) ? 'url' : 'id';
        try {
            $result = $this->client->callTool($server, $tool['name'], [$argument => $page['url']]);
        } catch (McpClientException $e) {
            $this->logger->warning('NotionLinkedPageReader: page read failed', [
                'server_id' => $server->getId(),
                'tool' => $tool['name'],
                'error' => $e->getMessage(),
            ]);

            return [
                'url' => $page['url'],
                'success' => false,
                'serverName' => $server->getName(),
                'text' => $this->plainError($e->getMessage()),
            ];
        }

        $text = $this->contentText($result['content']);
        if ($result['isError'] || '' === trim($text)) {
            return [
                'url' => $page['url'],
                'success' => false,
                'serverName' => $server->getName(),
                'text' => $this->plainError('' !== trim($text) ? $text : 'The connection returned no page content.'),
            ];
        }

        return [
            'url' => $page['url'],
            'success' => true,
            'serverName' => $server->getName(),
            'text' => mb_substr($text, 0, self::MAX_PAGE_CHARS),
        ];
    }

    /**
     * @param list<array<string, mixed>> $content
     */
    private function contentText(array $content): string
    {
        $parts = [];
        foreach ($content as $block) {
            if (is_string($block['text'] ?? null) && '' !== $block['text']) {
                $parts[] = $block['text'];
            }
        }

        return trim(implode("\n", $parts));
    }

    private function plainError(string $raw): string
    {
        $decoded = json_decode($raw, true);
        $message = is_array($decoded) && is_string($decoded['message'] ?? null) ? $decoded['message'] : trim($raw);
        if ('' === $message) {
            $message = 'The Notion connection could not open this page.';
        }

        return $message;
    }

    /**
     * @param list<array{url: string}> $pages
     *
     * @return list<array{url: string, success: bool, serverName: string, text: string}>
     */
    private function failures(array $pages, string $serverName, string $text): array
    {
        $failed = [];
        foreach ($pages as $page) {
            $failed[] = [
                'url' => $page['url'],
                'success' => false,
                'serverName' => $serverName,
                'text' => $text,
            ];
        }

        return $failed;
    }
}
