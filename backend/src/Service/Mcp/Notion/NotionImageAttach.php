<?php

declare(strict_types=1);

namespace App\Service\Mcp\Notion;

use App\Entity\McpServerConfig;
use App\Service\Mcp\McpClient;
use App\Service\Mcp\McpClientException;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Security\SsrfGuard;
use Psr\Log\LoggerInterface;

/**
 * Turns a Notion search into the first image on those pages. The picture
 * stays on Notion: the chat receives the short-lived download link and
 * nothing is written to local files.
 */
final readonly class NotionImageAttach
{
    public const MAX_PAGES = 5;

    private const FETCH_TOOL = 'notion-fetch';

    public function __construct(
        private McpClient $client,
        private SsrfGuard $ssrf,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function applies(string $serverUrl, string $tool, array $arguments): bool
    {
        if (NotionToolHints::HOST !== strtolower((string) parse_url($serverUrl, PHP_URL_HOST))) {
            return false;
        }
        $bare = strtolower($tool);
        $bare = str_starts_with($bare, 'notion-') ? substr($bare, strlen('notion-')) : $bare;
        if (NotionImagePlan::URLS_TOOL !== $bare) {
            return false;
        }
        $references = $arguments['references'] ?? null;

        return !is_array($references) || [] === $references;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function attach(McpServerConfig $server, string $tool, array $arguments, string $language = 'en'): NodeResult
    {
        $pages = $this->pages($arguments['pages'] ?? null);
        $looked = 0;
        foreach (array_slice($pages, 0, self::MAX_PAGES) as $page) {
            ++$looked;
            try {
                $markdown = $this->toolText($server, self::FETCH_TOOL, ['id' => $page['id']]);
            } catch (McpClientException $e) {
                $this->logger->warning('NotionImageAttach: page could not be opened', [
                    'server_id' => $server->getId(),
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
            $image = $this->firstImage($markdown);
            if (null === $image) {
                continue;
            }
            try {
                $url = $this->publicUrl($server, $tool, $image, $language);
            } catch (McpClientException $e) {
                $this->logger->warning('NotionImageAttach: download link failed', [
                    'server_id' => $server->getId(),
                    'error' => $e->getMessage(),
                ]);

                return NodeResult::reportableFailure(NotionImageCopy::noLink($language), $this->metadata($server, $tool));
            } catch (\RuntimeException $e) {
                return NodeResult::reportableFailure($e->getMessage(), $this->metadata($server, $tool));
            }

            return NodeResult::ok(
                NotionImageCopy::found($language, $image['name'], $page['title']),
                [['path' => $url, 'type' => 'image', 'name' => $image['name']]],
                $this->metadata($server, $tool),
            );
        }

        return NodeResult::ok(
            NotionImageCopy::none($language, $looked, self::MAX_PAGES),
            [],
            $this->metadata($server, $tool),
        );
    }

    /**
     * @return list<array{id: string, title: string}>
     */
    private function pages(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }
        $rows = is_array($raw['results'] ?? null) ? $raw['results'] : (array_is_list($raw) ? $raw : []);
        $pages = [];
        foreach ($rows as $row) {
            if (!is_array($row) || (isset($row['type']) && 'page' !== $row['type'])) {
                continue;
            }
            $id = is_string($row['id'] ?? null) ? trim($row['id']) : '';
            if ('' === $id) {
                continue;
            }
            $pages[] = [
                'id' => $id,
                'title' => is_string($row['title'] ?? null) && '' !== trim($row['title']) ? trim($row['title']) : 'Untitled',
            ];
        }

        return $pages;
    }

    /**
     * A Notion file block wins over an external markdown image.
     *
     * @return array{url: string, name: string, reference: ?string}|null
     */
    private function firstImage(string $markdown): ?array
    {
        $markdown = $this->pageMarkdown($markdown);
        foreach ($this->fileReferences($markdown) as $reference) {
            $name = $this->fileName($reference);
            if (null === $name || !$this->isImageName($name)) {
                continue;
            }

            return ['url' => '', 'name' => $name, 'reference' => $reference];
        }
        if (1 === preg_match('/!\[[^\]]*\]\((https:\/\/[^)\s]+)\)/', $markdown, $external)) {
            $name = $this->fileName($external[1]) ?? 'image';
            if ($this->isImageName($name)) {
                return ['url' => $external[1], 'name' => $name, 'reference' => null];
            }
        }

        return null;
    }

    /**
     * notion-fetch returns a JSON document. The page body, including the
     * image tag, is the `text` field. Scanning the raw JSON keeps the
     * escaped quote on the file name, so the picture is missed.
     */
    private function pageMarkdown(string $text): string
    {
        $decoded = json_decode($text, true);
        if (is_array($decoded) && is_string($decoded['text'] ?? null)) {
            return $decoded['text'];
        }

        return $text;
    }

    /**
     * An `<image>` tag is the picture. Other `notion-file-block://` mentions,
     * including more than one on the page, are only a fallback.
     *
     * @return list<string>
     */
    private function fileReferences(string $markdown): array
    {
        if (preg_match_all('/<image\b[^>]*\bsource=(["\'])(notion-file-block:\/\/.*?)\1/i', $markdown, $tagged) > 0) {
            return $tagged[2];
        }
        if (preg_match_all('/notion-file-block:\/\/[^\s"<>]+/i', $markdown, $any) > 0) {
            return $any[0];
        }

        return [];
    }

    /**
     * The signed Notion URL, checked only for being a public https address.
     * The bytes stay on Notion so the link the chat shows is the one the
     * browser loads.
     *
     * @param array{url: string, name: string, reference: ?string} $image
     */
    private function publicUrl(McpServerConfig $server, string $tool, array $image, string $language): string
    {
        $url = $image['url'];
        if (null !== $image['reference']) {
            $text = $this->toolText($server, $tool, ['references' => [$image['reference']]]);
            $url = $this->signedUrl($text) ?? '';
            if ('' === $url) {
                throw new \RuntimeException(NotionImageCopy::noLink($language));
            }
        }
        if (!str_starts_with($url, 'https://') || $this->ssrf->isBlockedUrl($url)) {
            throw new \RuntimeException(NotionImageCopy::notPublic($language));
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function toolText(McpServerConfig $server, string $tool, array $arguments): string
    {
        $result = $this->client->callTool($server, $tool, $arguments);
        $parts = [];
        foreach ($result['content'] as $block) {
            if ('text' === ($block['type'] ?? null) && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }
        if ($result['isError']) {
            throw new McpClientException('Notion reported an error for '.$tool);
        }

        return trim(implode("\n", $parts));
    }

    private function signedUrl(string $text): ?string
    {
        $decoded = json_decode($text, true);
        $rows = is_array($decoded) && is_array($decoded['files'] ?? null) ? $decoded['files'] : [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (['url', 'download_url', 'signed_url'] as $key) {
                if (is_string($row[$key] ?? null) && str_starts_with($row[$key], 'https://')) {
                    return $row[$key];
                }
            }
        }

        return null;
    }

    private function fileName(string $reference): ?string
    {
        $reference = rawurldecode(html_entity_decode($reference, ENT_QUOTES | ENT_HTML5));
        $value = $reference;
        if (1 === preg_match('/[?&]name=([^&\s"\']+)/', $reference, $match)) {
            $value = $match[1];
        } elseif (1 === preg_match('/([^\/\s?=&"\']+\.(?:png|jpe?g|gif|webp))/i', $reference, $match)) {
            $value = $match[1];
        } else {
            $path = parse_url($reference, PHP_URL_PATH);
            $value = is_string($path) ? basename($path) : $reference;
        }
        $value = rtrim(trim($value), '\\');

        return '' === $value ? null : $value;
    }

    private function isImageName(string $name): bool
    {
        return 1 === preg_match('/\.(png|jpe?g|gif|webp)$/i', $name);
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(McpServerConfig $server, string $tool): array
    {
        return [
            'mcp' => ['server_id' => $server->getId(), 'server' => $server->getName(), 'tool' => $tool],
            'query' => $server->getName().' · '.$tool,
        ];
    }
}
