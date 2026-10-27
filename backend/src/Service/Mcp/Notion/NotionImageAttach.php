<?php

declare(strict_types=1);

namespace App\Service\Mcp\Notion;

use App\Entity\File;
use App\Entity\McpServerConfig;
use App\Service\File\FileStorageService;
use App\Service\Mcp\McpClient;
use App\Service\Mcp\McpClientException;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Security\SsrfGuard;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Turns a Notion search into the first image on those pages and stores it
 * as a chat file. Notion search returns pages, not pictures: each page is
 * opened, its file reference is resolved to a short-lived URL, and that URL
 * is downloaded only when it is a public https image.
 */
final readonly class NotionImageAttach
{
    public const MAX_PAGES = 5;

    public const MAX_BYTES = 10_485_760;

    private const FETCH_TOOL = 'notion-fetch';

    private const MAX_REDIRECTS = 3;

    public function __construct(
        private McpClient $client,
        private HttpClientInterface $http,
        private SsrfGuard $ssrf,
        private FileStorageService $storage,
        private EntityManagerInterface $em,
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
    public function attach(McpServerConfig $server, string $tool, array $arguments, int $userId): NodeResult
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
                $file = $this->store($server, $tool, $image, $userId);
            } catch (McpClientException $e) {
                $this->logger->warning('NotionImageAttach: download link failed', [
                    'server_id' => $server->getId(),
                    'error' => $e->getMessage(),
                ]);

                return NodeResult::reportableFailure('Notion did not return a download link, so nothing was attached.', $this->metadata($server, $tool));
            } catch (\RuntimeException $e) {
                return NodeResult::reportableFailure($e->getMessage(), $this->metadata($server, $tool));
            }

            return NodeResult::ok(
                sprintf('Found image "%s" on page "%s".', $file['name'], $page['title']),
                [$file],
                $this->metadata($server, $tool),
            );
        }

        return NodeResult::ok(
            sprintf('Looked through %d of %d pages and found no image, so nothing was attached.', $looked, self::MAX_PAGES),
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
     * @param array{url: string, name: string, reference: ?string} $image
     *
     * @return array{path: string, type: string, local_path: string, name: string, file_id: int}
     */
    private function store(McpServerConfig $server, string $tool, array $image, int $userId): array
    {
        $url = $image['url'];
        if (null !== $image['reference']) {
            $text = $this->toolText($server, $tool, ['references' => [$image['reference']]]);
            $url = $this->signedUrl($text) ?? '';
            if ('' === $url) {
                throw new \RuntimeException('Notion did not return a download link, so nothing was attached.');
            }
        }
        $body = $this->download($url);
        if (!$this->looksLikeImage($body)) {
            throw new \RuntimeException('The file from Notion is not an image, so nothing was attached.');
        }

        $extension = strtolower(pathinfo($image['name'], PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/png',
        };
        $stored = $this->storage->storeRawContent($body, $userId, $image['name'], $mime);
        if (true !== $stored['success'] || '' === $stored['path']) {
            throw new \RuntimeException('The image could not be saved, so nothing was attached.');
        }

        $file = new File();
        $file->setUserId($userId);
        $file->setFilePath($stored['path']);
        $file->setFileName($image['name']);
        $file->setFileSize($stored['size']);
        $file->setFileMime($mime);
        $file->setFileType('' !== $extension ? $extension : 'png');
        $file->setStatus('complete');
        $file->setSource('chat_attachment');
        $file->setIncoming(false);
        $file->setEphemeral(true);
        $this->em->persist($file);
        $this->em->flush();

        return [
            'path' => '/api/v1/files/uploads/'.$stored['path'],
            'type' => 'image',
            'local_path' => $stored['path'],
            'name' => $image['name'],
            'file_id' => (int) ($file->getId() ?? 0),
        ];
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

    private function download(string $url): string
    {
        $current = $url;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            if (!str_starts_with($current, 'https://') || $this->ssrf->isBlockedUrl($current)) {
                throw new \RuntimeException('The image link is not a public address, so nothing was attached.');
            }
            try {
                $response = $this->http->request('GET', $current, [
                    'timeout' => 20,
                    'max_redirects' => 0,
                    'headers' => ['Accept' => 'image/*'],
                ] + $this->ssrf->pinnedResolveOption($current));
                $status = $response->getStatusCode();
            } catch (\Throwable $e) {
                $this->logger->warning('NotionImageAttach: download failed', ['error' => $e->getMessage()]);
                throw new \RuntimeException('The image could not be downloaded, so nothing was attached.');
            }
            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $location = $response->getHeaders(false)['location'][0] ?? '';
                $next = $this->nextUrl($current, $location);
                if (null === $next || self::MAX_REDIRECTS === $hop) {
                    throw new \RuntimeException('The image could not be downloaded, so nothing was attached.');
                }
                $current = $next;
                continue;
            }
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException('The image could not be downloaded, so nothing was attached.');
            }
            $length = (int) ($response->getHeaders(false)['content-length'][0] ?? 0);
            if ($length > self::MAX_BYTES) {
                throw new \RuntimeException('The image is larger than 10 MB, so nothing was attached.');
            }
            $body = $response->getContent();
            if (strlen($body) > self::MAX_BYTES) {
                throw new \RuntimeException('The image is larger than 10 MB, so nothing was attached.');
            }

            return $body;
        }

        throw new \RuntimeException('The image could not be downloaded, so nothing was attached.');
    }

    private function nextUrl(string $current, string $location): ?string
    {
        $location = trim($location);
        if (str_starts_with($location, 'https://')) {
            return $location;
        }
        if (!str_starts_with($location, '/')) {
            return null;
        }
        $host = parse_url($current, PHP_URL_HOST);

        return is_string($host) && '' !== $host ? 'https://'.$host.$location : null;
    }

    private function looksLikeImage(string $body): bool
    {
        return str_starts_with($body, "\x89PNG")
            || str_starts_with($body, "\xFF\xD8\xFF")
            || str_starts_with($body, 'GIF8')
            || (str_starts_with($body, 'RIFF') && str_contains(substr($body, 0, 16), 'WEBP'));
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
