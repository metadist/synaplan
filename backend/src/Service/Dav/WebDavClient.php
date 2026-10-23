<?php

declare(strict_types=1);

namespace App\Service\Dav;

use App\Service\Security\SsrfGuard;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Response\StreamableInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Minimal WebDAV client (connector plan 07 C10): PROPFIND to verify,
 * MKCOL to create folders, PUT to upload. One adapter covers Nextcloud,
 * ownCloud and any RFC 4918 server.
 *
 * Security posture per the connector sheet: HTTPS only, SSRF guard on every
 * request (the base URL is user-supplied), no redirects (a cross-host redirect
 * would replay the Basic credential elsewhere), and the credential never
 * appears in an exception message.
 *
 * Local Docker Nextcloud is the exception: both {@see $allowInsecureLocal}
 * and {@see $environment} === `dev` must be true. Production ignores the
 * flag even if an operator sets it by mistake.
 */
final readonly class WebDavClient
{
    private const TIMEOUT_SECONDS = 20;

    public function __construct(
        private HttpClientInterface $httpClient,
        private SsrfGuard $ssrfGuard,
        #[Autowire(env: 'default::bool:DAV_ALLOW_INSECURE_LOCAL')]
        private bool $allowInsecureLocal = false,
        #[Autowire('%kernel.environment%')]
        private string $environment = 'prod',
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
     * Children of a folder. Each row is a file, not the folder itself.
     *
     * @return list<array{name: string, path: string, size: int}>
     *
     * @throws DavException
     */
    public function list(DavTarget $target, string $path): array
    {
        $response = $this->request($target, 'PROPFIND', $path, [
            'Depth' => '1',
            'Content-Type' => 'application/xml; charset=utf-8',
        ], <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <d:propfind xmlns:d="DAV:"><d:prop><d:displayname/><d:getcontentlength/><d:resourcetype/></d:prop></d:propfind>
            XML);
        $this->assertSuccess($response['status'], 'PROPFIND', $path);

        return $this->parseListing($response['body'], $path);
    }

    /**
     * @throws DavException when the server answers with an error, or the body
     *                      grows past $maxBytes (0 means no cap)
     */
    public function get(DavTarget $target, string $path, int $maxBytes = 0): string
    {
        $response = $this->send($target, 'GET', $path);
        $this->assertSuccess($response->getStatusCode(), 'GET', $path);

        return $this->readCapped($response, $maxBytes);
    }

    /**
     * @return list<array{name: string, path: string, size: int}>
     */
    private function parseListing(string $xml, string $folder): array
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return [];
        }
        $out = [];
        $folderName = trim($folder, '/');
        foreach ($document->getElementsByTagNameNS('DAV:', 'response') as $response) {
            $href = '';
            $hrefNode = $response->getElementsByTagNameNS('DAV:', 'href')->item(0);
            if ($hrefNode instanceof \DOMNode) {
                $href = trim($hrefNode->textContent);
            }
            $filename = $this->hrefBasename($href);
            $props = $this->successfulProps($response);
            if ($props['collection'] || '' === $filename || str_contains($filename, '..')) {
                continue;
            }
            $name = '' !== $props['name'] ? $props['name'] : $filename;
            $out[] = [
                'name' => $name,
                'path' => trim($folderName.'/'.$filename, '/'),
                'size' => $props['size'],
            ];
        }

        return $out;
    }

    /**
     * @return array{name: string, size: int, collection: bool}
     */
    private function successfulProps(\DOMElement $response): array
    {
        $name = '';
        $size = 0;
        $collection = false;
        foreach ($response->getElementsByTagNameNS('DAV:', 'propstat') as $propstat) {
            if (!$this->propstatOk($propstat)) {
                continue;
            }
            $prop = $propstat->getElementsByTagNameNS('DAV:', 'prop')->item(0);
            if (!$prop instanceof \DOMElement) {
                continue;
            }
            $display = $prop->getElementsByTagNameNS('DAV:', 'displayname')->item(0);
            if ($display instanceof \DOMNode && '' !== trim($display->textContent)) {
                $name = trim($display->textContent);
            }
            $length = $prop->getElementsByTagNameNS('DAV:', 'getcontentlength')->item(0);
            if ($length instanceof \DOMNode && is_numeric(trim($length->textContent))) {
                $size = (int) trim($length->textContent);
            }
            $type = $prop->getElementsByTagNameNS('DAV:', 'resourcetype')->item(0);
            if ($type instanceof \DOMElement && $type->getElementsByTagNameNS('DAV:', 'collection')->length > 0) {
                $collection = true;
            }
        }

        return ['name' => $name, 'size' => $size, 'collection' => $collection];
    }

    private function propstatOk(\DOMElement $propstat): bool
    {
        $status = $propstat->getElementsByTagNameNS('DAV:', 'status')->item(0);
        if (!$status instanceof \DOMNode || '' === trim($status->textContent)) {
            return true;
        }

        return str_contains($status->textContent, '200');
    }

    private function hrefBasename(string $href): string
    {
        $path = parse_url($href, \PHP_URL_PATH);
        $path = is_string($path) && '' !== $path ? $path : $href;
        $name = rawurldecode(basename(rtrim($path, '/')));

        return str_contains($name, '/') || str_contains($name, '\\') ? '' : $name;
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
        $response = $this->send($target, $method, $path, $headers, $body);

        return [
            'status' => $response->getStatusCode(),
            'body' => $response->getContent(false),
        ];
    }

    /**
     * @param array<string, string> $headers
     *
     * @throws DavException
     */
    private function send(DavTarget $target, string $method, string $path, array $headers = [], ?string $body = null): ResponseInterface
    {
        $url = $this->buildUrl($target->baseUrl, $path);
        $insecureLocal = $this->allowsInsecureLocal();
        $scheme = strtolower((string) parse_url($url, \PHP_URL_SCHEME));
        if ('https' !== $scheme && !($insecureLocal && 'http' === $scheme)) {
            throw new DavException(0, 'Only https:// WebDAV addresses are allowed');
        }
        if (!$insecureLocal && $this->ssrfGuard->isBlockedUrl($url)) {
            throw new DavException(0, 'This WebDAV address points at a private or reserved network and was blocked');
        }

        try {
            return $this->httpClient->request($method, $url, [
                'auth_basic' => [$target->username, $target->password],
                'headers' => $headers,
                'body' => $body ?? '',
                'timeout' => self::TIMEOUT_SECONDS,
                'max_redirects' => 0,
            ]);
        } catch (TransportExceptionInterface $e) {
            throw new DavException(0, sprintf('Could not reach %s: %s', $target->host(), $e->getMessage()));
        }
    }

    /**
     * @throws DavException
     */
    private function readCapped(ResponseInterface $response, int $maxBytes): string
    {
        if ($maxBytes > 0 && $response instanceof StreamableInterface) {
            $stream = $response->toStream();
            $body = '';
            while (!feof($stream)) {
                $chunk = fread($stream, 8192);
                if (!is_string($chunk) || '' === $chunk) {
                    break;
                }
                $body .= $chunk;
                if (strlen($body) > $maxBytes) {
                    throw new DavException(0, 'This file is larger than the limit and was not kept');
                }
            }

            return $body;
        }

        $body = $response->getContent(false);
        if ($maxBytes > 0 && strlen($body) > $maxBytes) {
            throw new DavException(0, 'This file is larger than the limit and was not kept');
        }

        return $body;
    }

    /**
     * Dev-only: a local Nextcloud on the Docker network is http + private-IP.
     * Both the env flag and kernel environment must agree — a leaked
     * DAV_ALLOW_INSECURE_LOCAL=1 in production is a no-op.
     */
    private function allowsInsecureLocal(): bool
    {
        return $this->allowInsecureLocal && 'dev' === $this->environment;
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
