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
 * Downloads a document through the SSRF guard. Stops at 20 MB or 15 seconds.
 * The file is temporary; nothing is stored as the person's file.
 */
#[AsAlias(DocumentFetcher::class)]
final readonly class GuardedDocumentDownload implements DocumentFetcher
{
    public const MAX_BYTES = 20 * 1024 * 1024;
    public const TIMEOUT_SECONDS = 15;

    private const MAX_REDIRECTS = 3;
    private const USER_AGENT = 'Mozilla/5.0 (compatible; SynaplanBot/1.0; +https://synaplan.com/bot)';

    public function __construct(
        private HttpClientInterface $http,
        private SsrfGuard $ssrf,
    ) {
    }

    public function fetch(string $url, string $extension): DownloadedDocument
    {
        $current = $url;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            if ($this->ssrf->isBlockedUrl($current)) {
                return DownloadedDocument::blocked('blocked_address');
            }

            try {
                $response = $this->request($current);
                $status = $response->getStatusCode();
            } catch (TimeoutException) {
                return DownloadedDocument::blocked('timeout');
            } catch (TransportExceptionInterface) {
                return DownloadedDocument::blocked('download_failed');
            }

            if ($status >= 300 && $status < 400) {
                $location = $response->getHeaders(false)['location'][0] ?? '';
                if ('' === $location) {
                    return DownloadedDocument::blocked('download_failed');
                }
                $current = self::absoluteLocation($current, $location);
                continue;
            }

            if ($status < 200 || $status >= 300) {
                return DownloadedDocument::blocked('download_failed');
            }

            return $this->save($response, $extension);
        }

        return DownloadedDocument::blocked('download_failed');
    }

    private function request(string $url): ResponseInterface
    {
        return $this->http->request('GET', $url, $this->ssrf->pinnedResolveOption($url) + [
            'timeout' => self::TIMEOUT_SECONDS,
            'max_duration' => self::TIMEOUT_SECONDS,
            'max_redirects' => 0,
            'headers' => [
                'User-Agent' => self::USER_AGENT,
                'Accept' => '*/*',
            ],
        ]);
    }

    private function save(ResponseInterface $response, string $extension): DownloadedDocument
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
        try {
            foreach ($this->http->stream($response, (float) self::TIMEOUT_SECONDS) as $chunk) {
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
                fwrite($handle, $data);
            }
        } catch (TimeoutException) {
            $this->discard($response, $handle, $path);

            return DownloadedDocument::blocked('timeout');
        } catch (TransportExceptionInterface) {
            $this->discard($response, $handle, $path);

            return DownloadedDocument::blocked('download_failed');
        }

        fclose($handle);

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

    private static function absoluteLocation(string $current, string $location): string
    {
        if (1 === preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $parts = parse_url($current);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $location;
        }
        $origin = $parts['scheme'].'://'.$parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }
        // A scheme-relative Location (`//cdn.example/...`) names a new host.
        // Treating it as a path joins it onto the original origin.
        if (str_starts_with($location, '//')) {
            return $parts['scheme'].':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }
        $directory = isset($parts['path']) ? rtrim(\dirname($parts['path']), '/') : '';

        return $origin.$directory.'/'.$location;
    }
}
