<?php

declare(strict_types=1);

namespace App\Service\Microsoft;

use App\Entity\Connection;
use App\Service\OAuth\ConnectionAccessTokenProvider;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin Microsoft Graph reader for a user-owned connection.
 *
 * No SSRF guard here on purpose: unlike the MCP client, the host is a fixed
 * constant and never user-supplied. What Graph *does* require and the MCP
 * client does not is throttling discipline — Graph answers 429 with
 * `Retry-After` under normal load, and a mailbox poll that ignores it gets the
 * whole tenant throttled harder.
 */
final readonly class GraphClient
{
    private const BASE_URL = 'https://graph.microsoft.com/v1.0';
    private const TIMEOUT_SECONDS = 20;

    /** Bounded: a scheduled run must fail honestly rather than sit in a retry loop. */
    private const MAX_RETRIES = 2;
    private const MAX_RETRY_DELAY_SECONDS = 10;

    /** Graph caps `$top` at 1000; we stay far below that for a poll. */
    private const MAX_MESSAGES = 50;

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
     * Signed-in account behind the connection — used to name it after consent
     * and as the cheapest possible "does this still work?" probe.
     *
     * @return array{id: string, displayName: string, userPrincipalName: string, mail: string}
     */
    public function me(Connection $connection): array
    {
        $data = $this->get($connection, '/me', ['$select' => 'id,displayName,userPrincipalName,mail']);

        return [
            'id' => $this->str($data, 'id'),
            'displayName' => $this->str($data, 'displayName'),
            'userPrincipalName' => $this->str($data, 'userPrincipalName'),
            'mail' => $this->str($data, 'mail'),
        ];
    }

    /**
     * Recent messages, newest first (delegated `Mail.Read`).
     *
     * Bodies are excluded: a mailbox poll that pulls full bodies for every
     * message is both slow and a needless privacy exposure. Callers that need a
     * body fetch it per message.
     *
     * @return list<array{id: string, subject: string, from: string, receivedAt: string, preview: string, hasAttachments: bool, isRead: bool}>
     */
    public function listMessages(Connection $connection, int $limit = 10, ?string $folder = null): array
    {
        $top = max(1, min($limit, self::MAX_MESSAGES));
        $path = null !== $folder && '' !== $folder
            ? sprintf('/me/mailFolders/%s/messages', rawurlencode($folder))
            : '/me/messages';

        $data = $this->get($connection, $path, [
            '$top' => (string) $top,
            '$orderby' => 'receivedDateTime desc',
            '$select' => 'id,subject,from,receivedDateTime,bodyPreview,hasAttachments,isRead',
        ]);

        $value = $data['value'] ?? null;
        if (!is_array($value)) {
            return [];
        }

        $messages = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }
            $messages[] = [
                'id' => $this->str($item, 'id'),
                'subject' => $this->str($item, 'subject'),
                'from' => $this->senderAddress($item),
                'receivedAt' => $this->str($item, 'receivedDateTime'),
                'preview' => $this->str($item, 'bodyPreview'),
                'hasAttachments' => (bool) ($item['hasAttachments'] ?? false),
                'isRead' => (bool) ($item['isRead'] ?? false),
            ];
        }

        return $messages;
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function get(Connection $connection, string $path, array $query = []): array
    {
        $url = self::BASE_URL.$path;
        $token = $this->tokens->accessTokenFor($connection);
        $retriedAfter401 = false;

        for ($attempt = 0;; ++$attempt) {
            try {
                $response = $this->httpClient->request('GET', $url, [
                    'headers' => [
                        'Authorization' => 'Bearer '.$token,
                        'Accept' => 'application/json',
                    ],
                    'query' => $query,
                    'timeout' => self::TIMEOUT_SECONDS,
                    'max_redirects' => 0,
                ]);

                $status = $response->getStatusCode();
                $raw = $response->getContent(false);
                $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? null;
            } catch (TransportExceptionInterface $e) {
                throw new GraphException('Could not reach Microsoft Graph: '.$e->getMessage(), 0, $e);
            }

            if ($status >= 200 && $status < 300) {
                $decoded = json_decode($raw, true);

                return is_array($decoded) ? $decoded : [];
            }

            // A token can be revoked between our expiry check and the call.
            // Graph is the authority, so trust its 401 once and refresh.
            if (401 === $status && !$retriedAfter401) {
                $retriedAfter401 = true;
                $token = $this->tokens->refreshNow($connection);
                continue;
            }

            if ((429 === $status || $status >= 500) && $attempt < self::MAX_RETRIES) {
                $this->sleepBeforeRetry($retryAfter, $attempt, $status, $connection);
                continue;
            }

            throw new GraphException($this->describeFailure($status, $raw));
        }
    }

    private function sleepBeforeRetry(?string $retryAfter, int $attempt, int $status, Connection $connection): void
    {
        $delay = null !== $retryAfter && ctype_digit(trim($retryAfter))
            ? (int) trim($retryAfter)
            : 2 ** $attempt;
        $delay = max(1, min($delay, self::MAX_RETRY_DELAY_SECONDS));

        $this->logger->info('Microsoft Graph asked us to slow down', [
            'connection_id' => $connection->getId(),
            'status' => $status,
            'delay_seconds' => $delay,
            'attempt' => $attempt + 1,
        ]);

        ($this->sleeper ?? sleep(...))($delay);
    }

    /**
     * Graph errors are `{"error":{"code":"...","message":"..."}}`. Surfacing
     * both beats "Graph answered 403" when an admin has to fix a missing scope.
     */
    private function describeFailure(int $status, string $raw): string
    {
        $decoded = json_decode($raw, true);
        $error = is_array($decoded) ? ($decoded['error'] ?? null) : null;

        if (is_array($error)) {
            $code = is_string($error['code'] ?? null) ? $error['code'] : '';
            $message = is_string($error['message'] ?? null) ? $error['message'] : '';
            if ('' !== $code || '' !== $message) {
                return sprintf('Microsoft Graph answered HTTP %d (%s: %s)', $status, $code, $message);
            }
        }

        return sprintf('Microsoft Graph answered HTTP %d', $status);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function senderAddress(array $item): string
    {
        $from = $item['from'] ?? null;
        if (!is_array($from)) {
            return '';
        }
        $address = $from['emailAddress'] ?? null;
        if (!is_array($address)) {
            return '';
        }

        return is_string($address['address'] ?? null) ? $address['address'] : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function str(array $data, string $key): string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : '';
    }
}
