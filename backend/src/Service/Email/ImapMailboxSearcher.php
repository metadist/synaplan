<?php

declare(strict_types=1);

namespace App\Service\Email;

use App\Entity\InboundEmailHandler;

/**
 * IMAP implementation of {@see MailboxSearcher}.
 *
 * Reuses the InboundEmailHandler connection settings (server string identical
 * to InboundEmailHandlerService) and is strictly READ-ONLY: bodies are
 * fetched with FT_PEEK so the \Seen flag is never set, and no flag/delete
 * operation is ever issued (plan 09 §2.4).
 */
final readonly class ImapMailboxSearcher implements MailboxSearcher
{
    /** Per-message snippet cap — token control for the node output. */
    private const SNIPPET_CHARS = 2000;

    public function __construct(
        private MailServerAccess $mailServers,
    ) {
    }

    public function search(InboundEmailHandler $handler, string $query, ?string $from = null, ?string $since = null, int $limit = 10): array
    {
        $connection = $this->mailServers->openHandler($handler, readOnly: true);

        try {
            $criteria = self::buildCriteria($query, $from, $since);
            $uids = imap_search($connection, $criteria, \SE_UID);
            if (false === $uids || [] === $uids) {
                return [];
            }

            // Newest first, capped.
            rsort($uids);
            $uids = array_slice($uids, 0, max(1, $limit));

            $hits = [];
            $overviews = imap_fetch_overview($connection, implode(',', $uids), \FT_UID);
            foreach (is_array($overviews) ? $overviews : [] as $overview) {
                $uid = (int) ($overview->uid ?? 0);
                if ($uid <= 0) {
                    continue;
                }
                $hits[] = [
                    'from' => $this->decodeHeader((string) ($overview->from ?? '')),
                    'subject' => $this->decodeHeader((string) ($overview->subject ?? '')),
                    'date' => (string) ($overview->date ?? ''),
                    'snippet' => $this->fetchSnippet($connection, $uid),
                ];
            }

            // imap_fetch_overview returns mailbox order — restore newest first.
            usort($hits, static fn (array $a, array $b): int => strtotime($b['date']) <=> strtotime($a['date']));

            return $hits;
        } finally {
            imap_close($connection);
        }
    }

    /**
     * Build the IMAP SEARCH criteria string. Quotes are stripped from inputs —
     * IMAP has no escaping inside quoted strings, so embedded quotes would
     * break out of the criterion.
     */
    public static function buildCriteria(string $query, ?string $from = null, ?string $since = null): string
    {
        $sanitize = static fn (string $v): string => trim(str_replace('"', '', $v));

        $parts = [];
        $query = $sanitize($query);
        if ('' !== $query) {
            $parts[] = sprintf('TEXT "%s"', $query);
        }
        if (null !== $from && '' !== $sanitize($from)) {
            $parts[] = sprintf('FROM "%s"', $sanitize($from));
        }
        if (null !== $since) {
            $timestamp = strtotime($since);
            if (false !== $timestamp) {
                $parts[] = sprintf('SINCE "%s"', date('d-M-Y', $timestamp));
            }
        }

        return [] === $parts ? 'ALL' : implode(' ', $parts);
    }

    /**
     * First text part of the message, peek-fetched (never sets \Seen),
     * decoded best-effort and truncated.
     *
     * @param \IMAP\Connection $connection
     */
    private function fetchSnippet($connection, int $uid): string
    {
        $raw = @imap_fetchbody($connection, $uid, '1', \FT_UID | \FT_PEEK);
        if (!is_string($raw) || '' === $raw) {
            $raw = @imap_body($connection, $uid, \FT_UID | \FT_PEEK);
        }
        if (!is_string($raw)) {
            return '';
        }

        // Best-effort transfer-encoding decode: try quoted-printable (a no-op
        // for plain text) and base64 when the payload looks like it.
        $decoded = quoted_printable_decode($raw);
        if (1 === preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', trim($raw)) && strlen(trim($raw)) > 40) {
            $b64 = base64_decode(trim($raw), true);
            if (false !== $b64 && mb_check_encoding($b64, 'UTF-8')) {
                $decoded = $b64;
            }
        }

        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($decoded)));

        return mb_strlen($text) > self::SNIPPET_CHARS
            ? mb_substr($text, 0, self::SNIPPET_CHARS - 1).'…'
            : $text;
    }

    private function decodeHeader(string $header): string
    {
        $decoded = '';
        foreach (imap_mime_header_decode($header) ?: [] as $element) {
            $charset = strtolower((string) ($element->charset ?? 'default'));
            $text = (string) ($element->text ?? '');
            if (in_array($charset, ['default', '', 'utf-8', 'us-ascii'], true)) {
                $decoded .= $text;
                continue;
            }
            $converted = @mb_convert_encoding($text, 'UTF-8', strtoupper($charset));
            $decoded .= is_string($converted) ? $converted : $text;
        }

        return trim($decoded);
    }
}
