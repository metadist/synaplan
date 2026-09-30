<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Entity\McpServerConfig;
use App\Repository\McpServerConfigRepository;
use Psr\Log\LoggerInterface;

/**
 * Reads a pasted Confluence page through the user's Atlassian MCP connection.
 *
 * A public fetch of `*.atlassian.net/wiki/...` only sees the login screen.
 * When a connection such as Deskfiler is enabled, the page is loaded with
 * `getConfluenceContent` (or `getConfluencePage`) instead.
 */
final readonly class ConfluenceLinkedPageReader
{
    private const MAX_PAGE_CHARS = 12000;

    /** @var list<string> */
    private const READ_TOOLS = ['getConfluenceContent', 'getConfluencePage'];

    public function __construct(
        private McpServerConfigRepository $servers,
        private McpClient $client,
        private McpClientConfig $clientConfig,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string> $urls
     */
    public function read(array $urls, int $userId): ConfluenceLinkedPageRead
    {
        $pages = $this->confluencePages($urls);
        if ([] === $pages || $userId <= 0 || !$this->clientConfig->isClientEnabled($userId)) {
            return new ConfluenceLinkedPageRead([]);
        }

        $server = $this->atlassianServer($userId);
        if (null === $server) {
            return new ConfluenceLinkedPageRead([]);
        }

        try {
            $tools = $this->client->listTools($server);
        } catch (McpClientException $e) {
            $this->logger->warning('ConfluenceLinkedPageReader: tool list failed', [
                'server_id' => $server->getId(),
                'error' => $e->getMessage(),
            ]);

            return new ConfluenceLinkedPageRead($this->failures($pages, $server->getName(), $this->plainError($e->getMessage())));
        }

        $tool = $this->readTool($tools);
        if (null === $tool) {
            return new ConfluenceLinkedPageRead($this->failures(
                $pages,
                $server->getName(),
                'This connection does not offer a tool that can open a Confluence page.',
            ));
        }

        $read = [];
        foreach ($pages as $page) {
            $read[] = $this->readOne($server, $tool, $page);
        }

        return new ConfluenceLinkedPageRead($read);
    }

    /**
     * @param list<string> $urls
     *
     * @return list<array{url: string, site: string, pageId: string}>
     */
    private function confluencePages(array $urls): array
    {
        $pages = [];
        foreach ($urls as $url) {
            if (1 !== preg_match('#^https://([a-z0-9-]+)\.atlassian\.net/wiki/(?:spaces/[^/]+/)?(?:pages|blog)/(\d+)(?:[/?\#].*)?$#i', $url, $match)) {
                continue;
            }
            $pages[] = [
                'url' => $url,
                'site' => strtolower($match[1]),
                'pageId' => $match[2],
            ];
        }

        return $pages;
    }

    /**
     * A signed-in connection wins over a pasted token: Atlassian refuses
     * API tokens unless an organization admin allows them.
     */
    private function atlassianServer(int $userId): ?McpServerConfig
    {
        $signedIn = null;
        $token = null;
        $site = null;
        foreach ($this->servers->findEnabledByUser($userId) as $server) {
            $host = strtolower((string) parse_url($server->getUrl(), PHP_URL_HOST));
            if ('mcp.atlassian.com' === $host) {
                if ($server->isOAuth()) {
                    $signedIn ??= $server;
                } else {
                    $token ??= $server;
                }
            } elseif (str_ends_with($host, '.atlassian.net')) {
                $site ??= $server;
            }
        }

        return $signedIn ?? $token ?? $site;
    }

    /**
     * @param list<array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, mixed>}> $tools
     *
     * @return array{name: string, inputSchema: array<string, mixed>}|null
     */
    private function readTool(array $tools): ?array
    {
        $byName = [];
        foreach ($tools as $tool) {
            $byName[strtolower($tool['name'])] = $tool;
        }
        foreach (self::READ_TOOLS as $name) {
            $tool = $byName[strtolower($name)] ?? null;
            if (null !== $tool) {
                return ['name' => $tool['name'], 'inputSchema' => $tool['inputSchema']];
            }
        }

        return null;
    }

    /**
     * @param array{name: string, inputSchema: array<string, mixed>} $tool
     * @param array{url: string, site: string, pageId: string}       $page
     *
     * @return array{url: string, success: bool, serverName: string, text: string}
     */
    private function readOne(McpServerConfig $server, array $tool, array $page): array
    {
        try {
            $result = $this->client->callTool($server, $tool['name'], $this->arguments($tool['inputSchema'], $page));
        } catch (McpClientException $e) {
            $this->logger->warning('ConfluenceLinkedPageReader: page read failed', [
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
     * @param array<string, mixed>                             $schema
     * @param array{url: string, site: string, pageId: string} $page
     *
     * @return array<string, string>
     */
    private function arguments(array $schema, array $page): array
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $args = [];
        $cloudId = 'https://'.$page['site'].'.atlassian.net';
        if (isset($properties['cloudId'])) {
            $args['cloudId'] = $cloudId;
        }
        if (isset($properties['content_url'])) {
            $args['content_url'] = $page['url'];
        } elseif (isset($properties['url'])) {
            $args['url'] = $page['url'];
        }
        foreach (['content_id', 'contentId', 'pageId'] as $idField) {
            if (isset($properties[$idField])) {
                $args[$idField] = $page['pageId'];
                break;
            }
        }
        if (isset($properties['content_format'])) {
            $args['content_format'] = 'markdown';
        } elseif (isset($properties['contentFormat'])) {
            $args['contentFormat'] = 'markdown';
        }

        return $args;
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
            $message = 'The Confluence connection could not open this page.';
        }
        if (1 === preg_match('/api token/i', $message) && 1 === preg_match('/permission|admin/i', $message)) {
            $message .= ' An organization admin has to allow API-token access for this Atlassian connection, or sign in to the connection instead of using an API key.';
        }

        return $message;
    }

    /**
     * @param list<array{url: string, site: string, pageId: string}> $pages
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
