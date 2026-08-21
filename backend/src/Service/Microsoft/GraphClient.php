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

        return $this->mapMessages($data);
    }

    /**
     * Live message search (delegated `Mail.Read`), newest first.
     *
     * Uses Graph `$search` (KQL): free-text terms plus `from:` and
     * `received>=` qualifiers. `$search` results are relevance-ranked and
     * cannot be combined with `$orderby`, so we over-fetch a little and sort
     * by receivedDateTime client-side. Read-only; bodies are excluded — the
     * caller fetches a body per message via {@see messageBody()}.
     *
     * @param string|null $from  sender name or address (KQL `from:` qualifier)
     * @param string|null $since ISO date (YYYY-MM-DD) lower bound
     *
     * @return list<array{id: string, subject: string, from: string, receivedAt: string, preview: string, hasAttachments: bool, isRead: bool}>
     */
    public function searchMessages(Connection $connection, string $query, ?string $from = null, ?string $since = null, int $limit = 10): array
    {
        $limit = max(1, min($limit, self::MAX_MESSAGES));

        $kql = trim($query);
        if (null !== $from && '' !== trim($from)) {
            $kql .= ' from:'.$this->kqlValue(trim($from));
        }
        if (null !== $since && 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($since))) {
            $kql .= ' received>='.trim($since);
        }

        $data = $this->get($connection, '/me/messages', [
            // The whole KQL string is one quoted $search value; embedded
            // quotes (multi-word from:) are backslash-escaped per Graph rules.
            '$search' => '"'.$kql.'"',
            '$top' => (string) min($limit * 2, self::MAX_MESSAGES),
            '$select' => 'id,subject,from,receivedDateTime,bodyPreview,hasAttachments,isRead',
        ]);

        $messages = $this->mapMessages($data);
        usort(
            $messages,
            static fn (array $a, array $b): int => strcmp($b['receivedAt'], $a['receivedAt']),
        );

        return array_slice($messages, 0, $limit);
    }

    /**
     * Full body of one message (delegated `Mail.Read`), HTML converted to
     * plain text. Fetched per message on demand — never in bulk (privacy +
     * token budget).
     *
     * @return array{subject: string, from: string, receivedAt: string, body: string}
     */
    public function messageBody(Connection $connection, string $messageId): array
    {
        $data = $this->get($connection, '/me/messages/'.rawurlencode($messageId), [
            '$select' => 'subject,from,receivedDateTime,body',
        ]);

        $body = '';
        $rawBody = $data['body'] ?? null;
        if (is_array($rawBody) && is_string($rawBody['content'] ?? null)) {
            $body = $rawBody['content'];
            if ('html' === strtolower($this->str($rawBody, 'contentType'))) {
                $body = $this->htmlToText($body);
            }
        }

        return [
            'subject' => $this->str($data, 'subject'),
            'from' => $this->senderAddress($data),
            'receivedAt' => $this->str($data, 'receivedDateTime'),
            'body' => trim($body),
        ];
    }

    /**
     * Create a calendar event (delegated `Calendars.ReadWrite`).
     *
     * Idempotent by construction: the caller passes a deterministic
     * `transactionId` (max 255 chars) and Graph answers 409 for a repeat of
     * the same transaction — re-running a delivery never duplicates the
     * event, mirroring the CalDAV UID contract (plan 07 S13).
     *
     * @param list<string> $attendees email addresses
     *
     * @return array{id: string, webLink: string, created: bool} `created` is
     *                                                           false when the event already existed (409 on the transactionId)
     */
    public function createEvent(
        Connection $connection,
        string $transactionId,
        string $subject,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        string $timezone,
        ?string $body = null,
        ?string $location = null,
        array $attendees = [],
    ): array {
        $payload = [
            'transactionId' => mb_substr($transactionId, 0, 255),
            'subject' => $subject,
            'start' => ['dateTime' => $start->format('Y-m-d\TH:i:s'), 'timeZone' => $timezone],
            'end' => ['dateTime' => $end->format('Y-m-d\TH:i:s'), 'timeZone' => $timezone],
        ];
        if (null !== $body && '' !== trim($body)) {
            $payload['body'] = ['contentType' => 'text', 'content' => $body];
        }
        if (null !== $location && '' !== trim($location)) {
            $payload['location'] = ['displayName' => $location];
        }
        if ([] !== $attendees) {
            $payload['attendees'] = array_map(
                static fn (string $address): array => [
                    'emailAddress' => ['address' => $address],
                    'type' => 'required',
                ],
                $attendees,
            );
        }

        $result = $this->post($connection, '/me/events', $payload, conflictIsDuplicate: true);
        if (null === $result) {
            return ['id' => '', 'webLink' => '', 'created' => false];
        }

        return [
            'id' => $this->str($result, 'id'),
            'webLink' => $this->str($result, 'webLink'),
            'created' => true,
        ];
    }

    /**
     * Send a mail from the connected user's own mailbox (delegated
     * `Mail.Send`) — the message lands in their Sent items. Attachments are
     * inlined base64 (Graph's simple-attachment limit is ~3 MB per file;
     * larger files must go through a folder destination instead).
     *
     * @param list<string>                                                         $to
     * @param list<array{name: string, contentBytes: string, contentType: string}> $attachments contentBytes = base64
     */
    public function sendMail(
        Connection $connection,
        array $to,
        string $subject,
        string $body,
        array $attachments = [],
    ): void {
        $message = [
            'subject' => $subject,
            'body' => ['contentType' => 'text', 'content' => $body],
            'toRecipients' => array_map(
                static fn (string $address): array => ['emailAddress' => ['address' => $address]],
                $to,
            ),
        ];
        if ([] !== $attachments) {
            $message['attachments'] = array_map(
                static fn (array $a): array => [
                    '@odata.type' => '#microsoft.graph.fileAttachment',
                    'name' => $a['name'],
                    'contentType' => $a['contentType'],
                    'contentBytes' => $a['contentBytes'],
                ],
                $attachments,
            );
        }

        $this->post($connection, '/me/sendMail', ['message' => $message, 'saveToSentItems' => true]);
    }

    /**
     * @param array<string, mixed> $data a Graph collection payload (`value` list)
     *
     * @return list<array{id: string, subject: string, from: string, receivedAt: string, preview: string, hasAttachments: bool, isRead: bool}>
     */
    private function mapMessages(array $data): array
    {
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
     * Quote a KQL qualifier value when it contains whitespace; the quotes are
     * backslash-escaped because they sit inside the quoted `$search` string.
     */
    private function kqlValue(string $value): string
    {
        $value = str_replace('"', '', $value);

        return 1 === preg_match('/\s/', $value) ? '\\"'.$value.'\\"' : $value;
    }

    /** Plain-text rendering of an HTML mail body, whitespace normalized. */
    private function htmlToText(string $html): string
    {
        // Keep paragraph/line structure readable before stripping tags.
        $html = preg_replace('/<(br|\/p|\/div|\/tr|\/li)[^>]*>/i', "$0\n", $html) ?? $html;
        $html = preg_replace('/<(style|script)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00a0}", ' ', $text); // &nbsp; → plain space
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;

        return preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
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

    /**
     * POST with the same 401-refresh-once and 429/Retry-After discipline as
     * {@see get()}. 5xx is NOT retried — unlike a read, a write may have been
     * applied before the error, and only the 409/transactionId contract makes
     * a repeat safe ({@see createEvent()}); a 429 was rejected before
     * processing, so retrying it is always safe.
     *
     * @param array<string, mixed> $payload
     * @param bool                 $conflictIsDuplicate when true a 409 answer means
     *                                                  "this transaction already ran" and returns null instead of throwing
     *
     * @return array<string, mixed>|null the decoded response body (empty for 202-style answers), null on a duplicate 409
     */
    private function post(Connection $connection, string $path, array $payload, bool $conflictIsDuplicate = false): ?array
    {
        $url = self::BASE_URL.$path;
        $token = $this->tokens->accessTokenFor($connection);
        $retriedAfter401 = false;

        for ($attempt = 0;; ++$attempt) {
            try {
                $response = $this->httpClient->request('POST', $url, [
                    'headers' => [
                        'Authorization' => 'Bearer '.$token,
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $payload,
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

            if (401 === $status && !$retriedAfter401) {
                $retriedAfter401 = true;
                $token = $this->tokens->refreshNow($connection);
                continue;
            }

            if (409 === $status && $conflictIsDuplicate) {
                return null;
            }

            if (429 === $status && $attempt < self::MAX_RETRIES) {
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
