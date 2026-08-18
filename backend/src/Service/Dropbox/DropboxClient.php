<?php

declare(strict_types=1);

namespace App\Service\Dropbox;

use App\Entity\Connection;
use App\Service\OAuth\ConnectionAccessTokenProvider;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin Dropbox API client for a user-owned connection (connector plan 07 C13).
 *
 * Same discipline as {@see \App\Service\Microsoft\GraphClient}: fixed hosts
 * (never user-supplied, so no SSRF guard), one 401 retry after a forced token
 * refresh, and bounded 429/5xx retries honoring `Retry-After` — Dropbox
 * throttles per app, so a client that hammers on 429 degrades every user of
 * the installation.
 */
final readonly class DropboxClient
{
    private const RPC_BASE_URL = 'https://api.dropboxapi.com/2';
    private const CONTENT_BASE_URL = 'https://content.dropboxapi.com/2';
    private const TIMEOUT_SECONDS = 30;

    /** Bounded: a scheduled run must fail honestly rather than sit in a retry loop. */
    private const MAX_RETRIES = 2;
    private const MAX_RETRY_DELAY_SECONDS = 10;

    /** Single-call upload cap documented by Dropbox is 150 MB; stay well below. */
    public const MAX_UPLOAD_BYTES = 100 * 1024 * 1024;

    /**
     * @param (\Closure(int): void)|null $sleeper Overridden in tests so the
     *                                            throttling path can be exercised without real delays
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private ConnectionAccessTokenProvider $tokens,
        private LoggerInterface $logger,
        private ?\Closure $sleeper = null,
    ) {
    }

    /**
     * Account behind the connection — used to name it after consent and as the
     * cheapest possible "does this still work?" probe.
     *
     * @return array{accountId: string, name: string, email: string}
     */
    public function currentAccount(Connection $connection): array
    {
        $data = $this->request($connection, 'POST', self::RPC_BASE_URL.'/users/get_current_account', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => 'null',
        ]);

        $name = '';
        if (is_array($data['name'] ?? null) && is_string($data['name']['display_name'] ?? null)) {
            $name = $data['name']['display_name'];
        }

        return [
            'accountId' => is_string($data['account_id'] ?? null) ? $data['account_id'] : '',
            'name' => $name,
            'email' => is_string($data['email'] ?? null) ? $data['email'] : '',
        ];
    }

    /**
     * Upload one file (scoped `files.content.write`).
     *
     * `autorename` maps the registry's `rename` conflict policy onto Dropbox's
     * native behavior ("name (2).ext" server-side); `overwrite` uses the
     * overwrite write mode instead. Parent folders are created implicitly.
     *
     * @param string $remotePath absolute Dropbox path, e.g. "/Synaplan/report.docx"
     *
     * @return array{path: string, name: string} the path/name Dropbox actually stored
     *
     * @throws DropboxException
     * @throws \App\Service\OAuth\OAuthReauthRequiredException when the user must consent again
     * @throws \App\Service\OAuth\OAuthException               on a transient token failure
     */
    public function upload(Connection $connection, string $content, string $remotePath, bool $overwrite = false): array
    {
        $apiArg = json_encode([
            'path' => $remotePath,
            'mode' => $overwrite ? 'overwrite' : 'add',
            'autorename' => !$overwrite,
            'mute' => true,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $data = $this->request($connection, 'POST', self::CONTENT_BASE_URL.'/files/upload', [
            'headers' => [
                'Content-Type' => 'application/octet-stream',
                // Non-ASCII path characters must be escaped in this header
                // (HTTP headers are ASCII); JSON \uXXXX escapes do exactly that.
                'Dropbox-API-Arg' => $this->headerSafeJson($apiArg),
            ],
            'body' => $content,
        ]);

        return [
            'path' => is_string($data['path_display'] ?? null) ? $data['path_display'] : $remotePath,
            'name' => is_string($data['name'] ?? null) ? $data['name'] : basename($remotePath),
        ];
    }

    /** JSON with all non-ASCII characters \u-escaped, safe for an HTTP header value. */
    private function headerSafeJson(string $json): string
    {
        return preg_replace_callback(
            '/[\x7f-\x{10ffff}]/u',
            static fn (array $match): string => trim((string) json_encode($match[0]), '"'),
            $json,
        ) ?? $json;
    }

    /**
     * @param array{headers: array<string, string>, body: string} $options
     *
     * @return array<string, mixed>
     */
    private function request(Connection $connection, string $method, string $url, array $options): array
    {
        $token = $this->tokens->accessTokenFor($connection);
        $retriedAfter401 = false;

        for ($attempt = 0;; ++$attempt) {
            try {
                $response = $this->httpClient->request($method, $url, [
                    'headers' => $options['headers'] + ['Authorization' => 'Bearer '.$token],
                    'body' => $options['body'],
                    'timeout' => self::TIMEOUT_SECONDS,
                    'max_redirects' => 0,
                ]);

                $status = $response->getStatusCode();
                $raw = $response->getContent(false);
                $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? null;
            } catch (TransportExceptionInterface $e) {
                throw new DropboxException('Could not reach Dropbox: '.$e->getMessage(), '', 0, $e);
            }

            if ($status >= 200 && $status < 300) {
                $decoded = json_decode($raw, true);

                return is_array($decoded) ? $decoded : [];
            }

            // A token can be revoked between our expiry check and the call.
            // Dropbox is the authority, so trust its 401 once and refresh.
            if (401 === $status && !$retriedAfter401) {
                $retriedAfter401 = true;
                $token = $this->tokens->refreshNow($connection);
                continue;
            }

            if ((429 === $status || $status >= 500) && $attempt < self::MAX_RETRIES) {
                $this->sleepBeforeRetry($retryAfter, $attempt, $status, $connection);
                continue;
            }

            throw $this->describeFailure($status, $raw);
        }
    }

    private function sleepBeforeRetry(?string $retryAfter, int $attempt, int $status, Connection $connection): void
    {
        $delay = null !== $retryAfter && ctype_digit(trim($retryAfter))
            ? (int) trim($retryAfter)
            : 2 ** $attempt;
        $delay = max(1, min($delay, self::MAX_RETRY_DELAY_SECONDS));

        $this->logger->info('Dropbox asked us to slow down', [
            'connection_id' => $connection->getId(),
            'status' => $status,
            'delay_seconds' => $delay,
        ]);

        null !== $this->sleeper ? ($this->sleeper)($delay) : sleep($delay);
    }

    private function describeFailure(int $status, string $raw): DropboxException
    {
        $decoded = json_decode($raw, true);
        $summary = is_array($decoded) && is_string($decoded['error_summary'] ?? null)
            ? rtrim($decoded['error_summary'], '/.')
            : '';

        return new DropboxException(
            sprintf('Dropbox answered HTTP %d%s', $status, '' !== $summary ? ' ('.$summary.')' : ''),
            $summary,
            $status,
        );
    }
}
