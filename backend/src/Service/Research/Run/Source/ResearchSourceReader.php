<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Source;

use App\Plug\Extraction\ExtractionRegistry;
use App\Plug\Extraction\ExtractionRequest;
use App\Service\File\Office\StructuredTextExtractor;
use App\Service\Research\UrlContentResult;
use App\Service\Research\UrlContentService;
use Psr\Log\LoggerInterface;

/**
 * Reads one web URL for search and, later, for a research run.
 *
 * HTML goes through {@see UrlContentService}. PDF and Office files are
 * downloaded with a size and time cap and extracted without being saved as
 * the person's file. Cloud vision runs only when the caller allows it and
 * text extraction returned nothing.
 */
final class ResearchSourceReader implements ResearchSourceReaderInterface
{
    public const MAX_TEXT_CHARS = 100000;
    public const CACHE_SECONDS = 3600;
    private const CACHE_LIMIT = 200;

    /**
     * Uncompressed `word/document.xml` budget. The download cap only limits
     * the zip on disk; {@see \ZipArchive::getFromName()} would otherwise
     * materialize the whole entry.
     */
    public const MAX_DOCX_XML_BYTES = 20 * 1024 * 1024;

    /** @var list<string> */
    public const DOCUMENT_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];

    /** @var array<string, true> */
    private array $busyHosts = [];

    /**
     * Public page text, keyed by URL. `cloud` is true when a vision model
     * produced it, so a local read does not reuse that text.
     *
     * @var array<string, array{text: string, expires: int, cloud: bool}>
     */
    private array $cache = [];

    public function __construct(
        private readonly UrlContentService $pages,
        private readonly DocumentFetcher $documents,
        private readonly ExtractionRegistry $registry,
        private readonly StructuredTextExtractor $office,
        private readonly CloudDocumentReader $cloud,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function read(string $url, bool $allowCloud = true, ?int $userId = null, int $maxChars = self::MAX_TEXT_CHARS): SourceRead
    {
        $cached = $this->remembered($url, $allowCloud);
        if (null !== $cached) {
            return $cached->trimmed($maxChars);
        }

        $read = self::isDocumentUrl($url)
            ? $this->readDocument($url, $allowCloud, $userId)
            : $this->readHtml($url, $maxChars);

        return $read->trimmed($maxChars);
    }

    public function fetchForDeepen(string $url, int $maxChars, ?int $userId): UrlContentResult
    {
        $read = $this->read($url, true, $userId, $maxChars);
        $host = (string) parse_url($url, \PHP_URL_HOST);
        if ($read->blocked || '' === trim($read->text)) {
            $reason = '' !== $read->reason ? $read->reason : 'no_text';

            return new UrlContentResult($url, $read->text, '', $host, false, $reason, $url, $reason);
        }

        return new UrlContentResult($url, $read->text, '', $host, true, null, $url);
    }

    public static function isDocumentUrl(string $url): bool
    {
        return self::isDocumentExtension(self::extensionOf($url));
    }

    public static function isDocumentExtension(string $extension): bool
    {
        return \in_array(strtolower($extension), self::DOCUMENT_EXTENSIONS, true);
    }

    private function readHtml(string $url, int $maxChars): SourceRead
    {
        $page = $this->pages->fetchForReading($url, $maxChars);
        if (!$page->success) {
            return new SourceRead($url, true, $page->extractedText, $page->blockedReason ?? ($page->error ?? 'unread'));
        }
        $read = new SourceRead($url, false, $page->extractedText, '');
        $this->remember($url, $read->text, false);

        return $read;
    }

    private function readDocument(string $url, bool $allowCloud, ?int $userId): SourceRead
    {
        $host = strtolower((string) parse_url($url, \PHP_URL_HOST));
        if ('' === $host) {
            return new SourceRead($url, true, '', 'blocked_address');
        }
        if (isset($this->busyHosts[$host])) {
            return new SourceRead($url, true, '', 'host_busy');
        }

        $extension = self::extensionOf($url);
        $this->busyHosts[$host] = true;
        try {
            $downloaded = $this->documents->fetch($url, $extension);
        } finally {
            unset($this->busyHosts[$host]);
        }
        if (!$downloaded->ok) {
            return new SourceRead($url, true, '', $downloaded->reason);
        }

        try {
            [$text, $usedCloud] = $this->extract($downloaded->path, $extension, $userId, $allowCloud);
        } finally {
            if (is_file($downloaded->path)) {
                unlink($downloaded->path);
            }
        }

        $text = trim($text);
        if ('' === $text) {
            $this->logger->info('ResearchSourceReader: document has no text', [
                'host' => $host,
                'ext' => $extension,
            ]);

            return new SourceRead($url, true, '', 'unreadable');
        }

        $this->remember($url, $text, $usedCloud);

        return new SourceRead($url, false, $text, '');
    }

    /**
     * @return array{0: string, 1: bool} text and whether a vision model produced it
     */
    private function extract(string $path, string $extension, ?int $userId, bool $allowCloud): array
    {
        if ($this->office->supports($extension)) {
            $structured = $this->office->extract($path, $extension);
            if (\is_string($structured) && '' !== trim($structured)) {
                return [$structured, false];
            }
        }

        if ('docx' === $extension) {
            $docx = self::docxText($path);
            if ('' !== $docx) {
                return [$docx, false];
            }
        }

        $fromChain = $this->chainText($path, $extension, $userId, $allowCloud);
        if ('' !== trim($fromChain)) {
            return [$fromChain, false];
        }

        if ($allowCloud && 'pdf' === $extension) {
            return [$this->cloud->read($path, $extension, $userId), true];
        }

        return ['', false];
    }

    private function chainText(string $path, string $extension, ?int $userId, bool $allowCloud): string
    {
        $request = new ExtractionRequest($path, basename($path), self::mime($extension), $extension, $userId, false, 'document');
        $created = [];
        try {
            foreach ($this->registry->chainFor('document', $allowCloud) as $adapter) {
                if (!$adapter->supports($request)) {
                    continue;
                }
                try {
                    $result = $adapter->extract($request);
                } catch (\Throwable $e) {
                    $this->logger->info('ResearchSourceReader: extractor failed', [
                        'key' => $adapter->key(),
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
                if ($result->isRewrite() && \is_string($result->rewrittenAbsolutePath)) {
                    $created[] = $result->rewrittenAbsolutePath;
                    $nextExt = $result->rewrittenExt ?? $extension;
                    $request = $request->withPath($result->rewrittenAbsolutePath, $nextExt, self::mime($nextExt));
                    continue;
                }
                if ($result->hasText()) {
                    return $result->text;
                }
            }
        } finally {
            foreach ($created as $extra) {
                if (is_file($extra)) {
                    unlink($extra);
                }
            }
        }

        return '';
    }

    private function remembered(string $url, bool $allowCloud): ?SourceRead
    {
        $hit = $this->cache[$url] ?? null;
        if (null === $hit || $hit['expires'] < time()) {
            unset($this->cache[$url]);

            return null;
        }
        if ($hit['cloud'] && !$allowCloud) {
            return null;
        }

        return new SourceRead($url, false, $hit['text'], '');
    }

    private function remember(string $url, string $text, bool $cloud): void
    {
        if ('' === trim($text)) {
            return;
        }
        if (\count($this->cache) >= self::CACHE_LIMIT) {
            array_shift($this->cache);
        }
        $this->cache[$url] = [
            'text' => mb_substr($text, 0, self::MAX_TEXT_CHARS),
            'expires' => time() + self::CACHE_SECONDS,
            'cloud' => $cloud,
        ];
    }

    private static function docxText(string $path): string
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path)) {
            return '';
        }
        $stat = $zip->statName('word/document.xml');
        if (!\is_array($stat) || $stat['size'] > self::MAX_DOCX_XML_BYTES) {
            $zip->close();

            return '';
        }
        // The length stops a central-directory size of 0 from expanding without a bound.
        $xml = $zip->getFromName('word/document.xml', self::MAX_DOCX_XML_BYTES + 1);
        $zip->close();
        if (!\is_string($xml) || '' === $xml || \strlen($xml) > self::MAX_DOCX_XML_BYTES) {
            return '';
        }
        $withBreaks = str_replace(['</w:p>', '<w:tab/>', '<w:br/>'], ["\n", "\t", "\n"], $xml);
        $text = html_entity_decode(strip_tags($withBreaks), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $collapsed = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim(\is_string($collapsed) ? $collapsed : $text);
    }

    private static function extensionOf(string $url): string
    {
        $path = strtolower((string) parse_url($url, \PHP_URL_PATH));

        return pathinfo($path, \PATHINFO_EXTENSION);
    }

    private static function mime(string $extension): string
    {
        return match (strtolower($extension)) {
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            default => 'application/octet-stream',
        };
    }
}
