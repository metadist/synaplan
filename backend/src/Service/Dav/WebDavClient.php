<?php

declare(strict_types=1);

namespace App\Service\Dav;

use App\Service\Security\SsrfGuard;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimal WebDAV client (connector plan 07 C10): PROPFIND to verify,
 * MKCOL to create folders, PUT to upload. One adapter covers Nextcloud,
 * ownCloud and any RFC 4918 server.
 *
 * Security posture per the connector sheet: HTTPS only, SSRF guard on every
 * request (the base URL is user-supplied), no redirects (a cross-host redirect
 * would replay the Basic credential elsewhere), and the credential never
 * appears in an exception message.
 */
final readonly class WebDavClient
{
    private const TIMEOUT_SECONDS = 20;

    public function __construct(
        private HttpClientInterface $httpClient,
        private SsrfGuard $ssrfGuard,
    ) {
    }

    /**
     * True when the resource exists (PROPFIND Depth 0), false on 404.
     *
     * @throws DavException on any other failure
     */
    public function exists(DavTarget $target, string $path): bool
    {
        $response = $this->request($target, 'PROPFIND', $path, ['Depth' => '0']);
        if (404 === $response['status']) {
            return false;
        }
        $this->assertSuccess($response['status'], 'PROPFIND', $path);

        return true;
    }

    /**
     * Create a collection. An already-existing collection (405) is fine —
     * the caller only cares that the folder is there afterwards.
     *
     * @throws DavException
     */
    public function mkcol(DavTarget $target, string $path): void
    {
        $response = $this->request($target, 'MKCOL', $path);
        if (405 === $response['status']) {
            return;
        }
        $this->assertSuccess($response['status'], 'MKCOL', $path);
    }

    /**
     * Upload content. Returns the HTTP status (201 created / 204 overwritten),
     * or 412 when `If-None-Match: *` was requested and the resource exists —
     * the caller decides whether that is a conflict or an idempotent success.
     *
     * @param array<string, string> $extraHeaders
     *
     * @throws DavException
     */
    public function put(DavTarget $target, string $path, string $content, string $contentType, array $extraHeaders = []): int
    {
        $headers = ['Content-Type' => $contentType] + $extraHeaders;
        $response = $this->request($target, 'PUT', $path, $headers, $content);
        if (412 === $response['status'] && isset($extraHeaders['If-None-Match'])) {
            return 412;
        }
        $this->assertSuccess($response['status'], 'PUT', $path);

        return $response['status'];
    }

    /**
     * CalDAV REPORT (calendar-query and friends). Returns the raw multistatus
     * XML body.
     *
     * @throws DavException
     */
    public function report(DavTarget $target, string $path, string $xmlBody): string
    {
        $response = $this->request($target, 'REPORT', $path, [
            'Depth' => '1',
            'Content-Type' => 'application/xml; charset=utf-8',
        ], $xmlBody);
        $this->assertSuccess($response['status'], 'REPORT', $path);

        return $response['body'];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: string}
     *
     * @throws DavException
     */
    private function request(DavTarget $target, string $method, string $path, array $headers = [], ?string $body = null): array
    {
        $url = $this->buildUrl($target->baseUrl, $path);

        if ('https' !== strtolower((string) parse_url($url, \PHP_URL_SCHEME))) {
            throw new DavException(0, 'Only https:// WebDAV addresses are allowed');
        }
        if ($this->ssrfGuard->isBlockedUrl($url)) {
            throw new DavException(0, 'This WebDAV address points at a private or reserved network and was blocked');
        }

        try {
            $response = $this->httpClient->request($method, $url, [
                'auth_basic' => [$target->username, $target->password],
                'headers' => $headers,
                'body' => $body ?? '',
                'timeout' => self::TIMEOUT_SECONDS,
                'max_redirects' => 0,
            ]);

            return [
                'status' => $response->getStatusCode(),
                'body' => $response->getContent(false),
            ];
        } catch (TransportExceptionInterface $e) {
            throw new DavException(0, sprintf('Could not reach %s: %s', $target->host(), $e->getMessage()));
        }
    }

    /**
     * Join the base URL and a relative path, encoding each path segment while
     * keeping the separators.
     */
    private function buildUrl(string $baseUrl, string $path): string
    {
        $url = rtrim($baseUrl, '/');
        $trimmed = trim($path, '/');
        if ('' === $trimmed) {
            return $url;
        }

        $segments = array_map(rawurlencode(...), explode('/', $trimmed));

        return $url.'/'.implode('/', $segments);
    }

    private function assertSuccess(int $status, string $method, string $path): void
    {
        // 2xx covers 207 Multi-Status, the success shape of PROPFIND/REPORT.
        if ($status >= 200 && $status < 300) {
            return;
        }

        throw new DavException($status, sprintf('%s on "%s" answered HTTP %d', $method, $path, $status));
    }
}
