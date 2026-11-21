<?php

declare(strict_types=1);

namespace Plugin\Synaform\Service;

use App\Service\Egress\EgressCall;
use App\Service\Security\SsrfGuard;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Reads one public page for an applicant CV.
 *
 * The address has to be http(s), a real external host, and an answer in the
 * 2xx range. Internal names, loopback, link-local (including cloud metadata)
 * and any host that does not resolve to a public address are refused before
 * a request is sent. Redirects are not followed until the next address
 * passes the same check. A 404 or any other error is not turned into CV
 * text: the caller gets a warning and no body.
 */
final readonly class ApplicantUrlChecker
{
    public const HTTP_ONLY = 'Only http:// and https:// URLs are supported';

    public const PUBLIC_ONLY = 'Only a public web address can be read. Internal and private addresses are not allowed.';

    private const MAX_REDIRECTS = 5;

    private const SNIPPET_CAP = 60000;

    private HttpClientInterface $http;

    public function __construct(
        HttpClientInterface $http,
        private SsrfGuard $ssrfGuard,
    ) {
        $this->http = new NoPrivateNetworkHttpClient($http);
    }

    public function check(string $url): ApplicantUrlFetch
    {
        $refusal = $this->refusal($url);
        if (null !== $refusal) {
            return new ApplicantUrlFetch(null, $refusal, true);
        }

        $current = $url;
        $deadline = microtime(true) + 15.0;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0.0) {
                return new ApplicantUrlFetch(null, $this->describe($this->hostOf($current), 0, 'Could not reach the page'), false);
            }

            $fetch = $this->fetchOnce($current, $remaining);
            if ($fetch instanceof ApplicantUrlFetch) {
                return $fetch;
            }

            if (self::MAX_REDIRECTS === $hop) {
                return new ApplicantUrlFetch(null, 'The page redirected too many times. The text was not read.', false);
            }

            $nextRefusal = $this->refusal($fetch);
            if (null !== $nextRefusal) {
                return new ApplicantUrlFetch(null, $nextRefusal, true);
            }
            $current = $fetch;
        }

        return new ApplicantUrlFetch(null, 'The page redirected too many times. The text was not read.', false);
    }

    /**
     * Null when this exact URL may be requested. The message is the warning
     * to show when it may not.
     */
    public function refusal(string $url): ?string
    {
        $url = trim($url);
        if ('' === $url || false !== strpbrk($url, "\r\n\0")) {
            return self::HTTP_ONLY;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return self::HTTP_ONLY;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if (!in_array($scheme, ['http', 'https'], true) || '' === $host) {
            return self::HTTP_ONLY;
        }

        // A single label is a container or sidecar name (backend, db, tika),
        // not a public site an applicant would publish a CV on.
        if (false === filter_var($host, \FILTER_VALIDATE_IP) && !str_contains($host, '.')) {
            return self::PUBLIC_ONLY;
        }

        if ([] === $this->ssrfGuard->pinnedIps($host)) {
            return self::PUBLIC_ONLY;
        }

        return null;
    }

    /**
     * @return ApplicantUrlFetch|string the next absolute URL when the response is a redirect
     */
    private function fetchOnce(string $url, float $remaining): ApplicantUrlFetch|string
    {
        $options = $this->requestOptions($url, $remaining);
        if ($options instanceof ApplicantUrlFetch) {
            return $options;
        }

        try {
            $response = $this->http->request('GET', $url, $options);
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            return $this->transportResult($url, $e);
        }

        if ($status >= 300 && $status < 400) {
            $location = $this->redirectTarget($response);
            if (null === $location) {
                return new ApplicantUrlFetch(null, 'The page redirected without a new address. The text was not read.', false);
            }
            $next = $this->resolveRedirect($url, $location);

            return null === $next
                ? new ApplicantUrlFetch(null, self::PUBLIC_ONLY, true)
                : $next;
        }

        $host = strtolower(trim((string) parse_url($url, \PHP_URL_HOST), '[]'));
        if ($status < 200 || $status >= 300) {
            return new ApplicantUrlFetch(null, $this->describe($host, $status, null), false);
        }

        try {
            $raw = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            return $this->transportResult($url, $e);
        }

        return $this->readablePage($host, $raw);
    }

    private function transportResult(string $url, TransportExceptionInterface $exception): ApplicantUrlFetch
    {
        if (str_contains($exception->getMessage(), 'is blocked') || null !== $this->refusal($url)) {
            return new ApplicantUrlFetch(null, self::PUBLIC_ONLY, true);
        }

        return new ApplicantUrlFetch(null, $this->describe($this->hostOf($url), 0, 'Could not reach the page'), false);
    }

    /**
     * Options for one hop, or a refusal when a hostname no longer has a
     * public address to pin. The pin is the address this call just accepted,
     * so a later lookup cannot move the connection.
     *
     * @return array<string, mixed>|ApplicantUrlFetch
     */
    private function requestOptions(string $url, float $remaining): array|ApplicantUrlFetch
    {
        $options = [
            'timeout' => $remaining,
            'max_duration' => $remaining,
            'max_redirects' => 0,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; Synaform-Bot/1.0; +https://synaplan.com)',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9,de;q=0.8',
            ],
        ];
        $host = $this->hostOf($url);
        if (false === filter_var($host, \FILTER_VALIDATE_IP)) {
            $pinned = $this->ssrfGuard->pinnedIps($host);
            if ([] === $pinned) {
                return new ApplicantUrlFetch(null, self::PUBLIC_ONLY, true);
            }
            $options['resolve'] = [$host => $pinned[0]];
        }

        return EgressCall::typed($options);
    }

    private function hostOf(string $url): string
    {
        return strtolower(trim((string) parse_url($url, \PHP_URL_HOST), '[]'));
    }

    private function redirectTarget(ResponseInterface $response): ?string
    {
        $headers = $response->getHeaders(false);
        $location = $headers['location'][0] ?? null;
        if (!is_string($location) || '' === trim($location)) {
            return null;
        }

        return trim($location);
    }

    private function resolveRedirect(string $current, string $location): ?string
    {
        if (str_contains($location, "\n") || str_contains($location, "\r")) {
            return null;
        }
        if (str_starts_with($location, '//')) {
            $scheme = parse_url($current, \PHP_URL_SCHEME);

            return is_string($scheme) && '' !== $scheme ? $scheme.':'.$location : null;
        }

        $locationParts = parse_url($location);
        if (is_array($locationParts) && isset($locationParts['scheme'])) {
            return $location;
        }

        $base = parse_url($current);
        if (!is_array($base) || !isset($base['scheme'], $base['host'])) {
            return null;
        }

        $origin = $base['scheme'].'://'.$base['host'];
        if (isset($base['port'])) {
            $origin .= ':'.$base['port'];
        }
        $path = $base['path'] ?? '/';
        if (str_starts_with($location, '?')) {
            return $origin.$path.$location;
        }
        if (str_starts_with($location, '#')) {
            $query = isset($base['query']) ? '?'.$base['query'] : '';

            return $origin.$path.$query.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $slash = strrpos($path, '/');
        $dir = false === $slash ? '/' : substr($path, 0, $slash + 1);

        return $origin.$dir.$location;
    }

    private function readablePage(string $host, string $raw): ApplicantUrlFetch
    {
        $cleaned = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $raw) ?? '';
        $cleaned = preg_replace('#<!--.*?-->#s', ' ', $cleaned) ?? '';
        $cleaned = html_entity_decode(strip_tags($cleaned), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $cleaned = preg_replace('/[ \t]+/', ' ', $cleaned) ?? '';
        $cleaned = preg_replace("/\n{3,}/", "\n\n", $cleaned) ?? '';
        $cleaned = trim($cleaned);

        if (mb_strlen($cleaned) > self::SNIPPET_CAP) {
            $cleaned = mb_substr($cleaned, 0, self::SNIPPET_CAP)."\n…[truncated]…";
        }

        $loginShell = $this->isLoginWalledHost($host) && mb_strlen($cleaned) < 600
            && 1 === preg_match('/\b(sign in|log in|join now|anmelden|einloggen|connectez-vous)\b/i', $cleaned);
        if ($loginShell || '' === $cleaned) {
            return new ApplicantUrlFetch(null, $this->describe($host, 200, '' === $cleaned ? 'No extractable text' : null), false);
        }

        return new ApplicantUrlFetch($cleaned, null, false);
    }

    private function describe(string $host, int $status, ?string $transportError): string
    {
        if (429 === $status) {
            return 'The site rate-limited the fetch (HTTP 429). Try again in a minute.';
        }
        if (str_contains($host, 'linkedin.com')) {
            return 'LinkedIn blocks server-side fetches of profile pages. Open the profile in your browser, choose “More → Save to PDF”, and upload the PDF as a CV instead. The URL stays attached as a reference.';
        }
        if (str_contains($host, 'instagram.com')) {
            return 'Instagram blocks server-side fetches. Take a screenshot of the profile and upload it as an additional document instead. The URL stays attached as a reference.';
        }
        if (str_contains($host, 'facebook.com') || str_contains($host, 'fb.com')) {
            return 'Facebook blocks server-side fetches of profile pages. Save the page as PDF from your browser and upload it as an additional document instead. The URL stays attached as a reference.';
        }
        if ('x.com' === $host || str_ends_with($host, '.x.com') || str_contains($host, 'twitter.com')) {
            return 'X (Twitter) blocks server-side fetches without authentication. Copy the relevant content into the description field, or take a screenshot. The URL stays attached as a reference.';
        }
        if (0 === $status && null !== $transportError) {
            return 'Could not reach the URL: '.$transportError;
        }
        if ($status >= 400) {
            return 'The site returned HTTP '.$status.' — the page is not publicly readable by a server.';
        }
        if (null !== $transportError) {
            return $transportError;
        }

        return 'No extractable text on the page.';
    }

    private function isLoginWalledHost(string $host): bool
    {
        foreach (['linkedin.com', 'instagram.com', 'facebook.com', 'x.com', 'twitter.com'] as $needle) {
            if (str_contains($host, $needle)) {
                return true;
            }
        }

        return false;
    }
}
