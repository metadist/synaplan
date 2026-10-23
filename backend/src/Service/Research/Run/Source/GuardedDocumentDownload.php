<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Source;

use App\Service\Security\SsrfGuard;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Downloads a document through the SSRF guard. Stops at 20 MB or 15 seconds
 * in total, redirects included. The body must start like the file type the
 * URL names, so an HTML login page behind a `.pdf` link is never read as the
 * document. The file is temporary; nothing is stored as the person's file.
 */
#[AsAlias(DocumentFetcher::class)]
final readonly class GuardedDocumentDownload implements DocumentFetcher
{
    public const MAX_BYTES = 20 * 1024 * 1024;
    public const TIMEOUT_SECONDS = 15;

    private const MAX_REDIRECTS = 3;
    private const USER_AGENT = 'Mozilla/5.0 (compatible; SynaplanBot/1.0; +https://synaplan.com/bot)';

    /** PDF allows up to 1024 bytes of junk before the `%PDF-` marker. */
    private const SIGNATURE_WINDOW_BYTES = 1024;
    private const PDF_SIGNATURE = '%PDF-';
    private const ZIP_SIGNATURE = "PK\x03\x04";
    private const OLE_SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    public function __construct(
        private HttpClientInterface $http,
        private SsrfGuard $ssrf,
    ) {
    }

    public function fetch(string $url, string $extension, int $timeoutSeconds = self::TIMEOUT_SECONDS): DownloadedDocument
    {
        $deadline = microtime(true) + max(1, min($timeoutSeconds, self::TIMEOUT_SECONDS));
        $current = $url;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return DownloadedDocument::blocked('timeout');
            }
            if ($this->ssrf->isBlockedUrl($current)) {
                return DownloadedDocument::blocked('blocked_address');
            }

            try {
                $response = $this->request($current, $remaining);
                $status = $response->getStatusCode();
            } catch (TimeoutException) {
                return DownloadedDocument::blocked('timeout');
            } catch (TransportExceptionInterface) {
                return DownloadedDocument::blocked('download_failed');
            }

            if ($status >= 300 && $status < 400) {
                $location = trim($response->getHeaders(false)['location'][0] ?? '');
                $response->cancel();
                if ('' === $location) {
                    return DownloadedDocument::blocked('download_failed');
                }
                $current = self::absoluteLocation($current, $location);
                continue;
            }

            if ($status < 200 || $status >= 300) {
                $response->cancel();

                return DownloadedDocument::blocked('download_failed');
            }

            return $this->save($response, $extension, $remaining);
        }

        return DownloadedDocument::blocked('download_failed');
    }

    private function request(string $url, float $remainingSeconds): ResponseInterface
    {
        return $this->http->request('GET', $url, $this->ssrf->pinnedResolveOption($url) + [
            'timeout' => $remainingSeconds,
            'max_duration' => $remainingSeconds,
            'max_redirects' => 0,
            'headers' => [
                'User-Agent' => self::USER_AGENT,
                'Accept' => '*/*',
            ],
        ]);
    }

    private function save(ResponseInterface $response, string $extension, float $remainingSeconds): DownloadedDocument
    {
        $length = (int) ($response->getHeaders(false)['content-length'][0] ?? 0);
        if ($length > self::MAX_BYTES) {
            $response->cancel();

            return DownloadedDocument::blocked('too_large');
        }

        $path = self::tempPath($extension);
        if (null === $path) {
            $response->cancel();

            return DownloadedDocument::blocked('download_failed');
        }

        $handle = fopen($path, 'wb');
        if (false === $handle) {
            $response->cancel();
            @unlink($path);

            return DownloadedDocument::blocked('download_failed');
        }

        $bytes = 0;
        $head = '';
        try {
            foreach ($this->http->stream($response, $remainingSeconds) as $chunk) {
                if ($chunk->isTimeout()) {
                    $this->discard($response, $handle, $path);

                    return DownloadedDocument::blocked('timeout');
                }
                if (null !== $chunk->getError()) {
                    $this->discard($response, $handle, $path);

                    return DownloadedDocument::blocked('download_failed');
                }
                $data = $chunk->getContent();
                $bytes += \strlen($data);
                if ($bytes > self::MAX_BYTES) {
                    $this->discard($response, $handle, $path);

                    return DownloadedDocument::blocked('too_large');
                }
                if (\strlen($head) < self::SIGNATURE_WINDOW_BYTES) {
                    $head .= substr($data, 0, self::SIGNATURE_WINDOW_BYTES - \strlen($head));
                }
                if (false === fwrite($handle, $data)) {
                    $this->discard($response, $handle, $path);

                    return DownloadedDocument::blocked('download_failed');
                }
            }
        } catch (TimeoutException) {
            $this->discard($response, $handle, $path);

            return DownloadedDocument::blocked('timeout');
        } catch (TransportExceptionInterface) {
            $this->discard($response, $handle, $path);

            return DownloadedDocument::blocked('download_failed');
        }

        fclose($handle);

        if (!self::looksLike($head, $extension)) {
            unlink($path);

            return DownloadedDocument::blocked('unsupported_content');
        }

        return DownloadedDocument::saved($path);
    }

    /**
     * @param resource $handle
     */
    private function discard(ResponseInterface $response, $handle, string $path): void
    {
        $response->cancel();
        fclose($handle);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private static function looksLike(string $head, string $extension): bool
    {
        return match (strtolower($extension)) {
            'pdf' => str_contains($head, self::PDF_SIGNATURE),
            'docx', 'xlsx', 'pptx' => str_starts_with($head, self::ZIP_SIGNATURE),
            'doc', 'xls', 'ppt' => str_starts_with($head, self::OLE_SIGNATURE),
            default => '' !== $head,
        };
    }

    private static function tempPath(string $extension): ?string
    {
        $safe = preg_replace('/[^a-z0-9]/', '', strtolower($extension)) ?? '';
        if ('' === $safe) {
            $safe = 'bin';
        }
        $temporary = tempnam(sys_get_temp_dir(), 'synaplan-doc-');
        if (false === $temporary) {
            return null;
        }
        $path = $temporary.'.'.$safe;
        if (!rename($temporary, $path)) {
            @unlink($temporary);

            return null;
        }

        return $path;
    }

    /**
     * Resolves a `Location` header against the URL that sent it (RFC 3986 §5.2).
     */
    private static function absoluteLocation(string $current, string $location): string
    {
        if (1 === preg_match('#^[a-z][a-z0-9+.\-]*:#i', $location)) {
            return $location;
        }
        $parts = parse_url($current);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $location;
        }
        $scheme = strtolower($parts['scheme']);
        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }

        $origin = $scheme.'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $basePath = $parts['path'] ?? '/';
        if (str_starts_with($location, '?')) {
            return $origin.$basePath.$location;
        }

        $suffix = '';
        $cut = strcspn($location, '?#');
        if ($cut < \strlen($location)) {
            $suffix = substr($location, $cut);
            $location = substr($location, 0, $cut);
        }
        if ('' === $location) {
            $query = isset($parts['query']) ? '?'.$parts['query'] : '';

            return $origin.$basePath.$query.$suffix;
        }

        $path = str_starts_with($location, '/')
            ? $location
            : substr($basePath, 0, (int) strrpos($basePath, '/') + 1).$location;

        return $origin.self::removeDotSegments($path).$suffix;
    }

    private static function removeDotSegments(string $path): string
    {
        $output = [];
        $segments = explode('/', $path);
        $last = \count($segments) - 1;
        foreach ($segments as $index => $segment) {
            if ('..' === $segment) {
                if (\count($output) > 1) {
                    array_pop($output);
                }
                if ($index === $last) {
                    $output[] = '';
                }
                continue;
            }
            if ('.' === $segment) {
                if ($index === $last) {
                    $output[] = '';
                }
                continue;
            }
            $output[] = $segment;
        }

        $resolved = implode('/', $output);

        return str_starts_with($resolved, '/') ? $resolved : '/'.$resolved;
    }
}
