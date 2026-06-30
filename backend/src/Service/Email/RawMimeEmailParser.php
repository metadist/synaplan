<?php

declare(strict_types=1);

namespace App\Service\Email;

/**
 * Best-effort parser that turns a RAW MIME message string into readable text.
 *
 * Issue #1077: the email webhook (`WebhookController::email()`) expects the
 * relay to POST a pre-parsed `text/plain` body. Some relays instead forward the
 * untouched MIME source — multipart boundaries, `Content-Type` /
 * `Content-Transfer-Encoding` headers, quoted-printable text and base64-encoded
 * attachments. Stored as-is, that blob is shown as the chat message and the AI
 * pipeline never sees the real question, so no reply is sent.
 *
 * This is a deliberately small, dependency-free recursive MIME walker (the
 * `imap_*` based parser in {@see \App\Service\InboundEmailHandlerService} needs a
 * live IMAP connection and cannot be reused on a plain string). It extracts the
 * first usable `text/plain` part, falling back to a tag-stripped `text/html`
 * part, and skips explicit attachments. Attachment handling stays the relay's
 * job via the webhook's dedicated `attachments` field.
 */
final readonly class RawMimeEmailParser
{
    private const MAX_DEPTH = 20;

    /**
     * Heuristically decide whether a webhook body is raw MIME rather than the
     * expected pre-parsed plain text.
     *
     * Requires a `Content-Type` header at the start of a line PLUS a structural
     * signal (a multipart boundary, a transfer encoding, or a MIME-Version
     * header). The combined check keeps ordinary prose — even prose that happens
     * to mention "Content-Type" mid-sentence — from being misclassified.
     */
    public function looksLikeRawMime(string $body): bool
    {
        $sample = substr($body, 0, 8192);

        if (1 !== preg_match('/^content-type:\s*\S+/im', $sample)) {
            return false;
        }

        return 1 === preg_match('/boundary\s*=/i', $sample)
            || 1 === preg_match('/^content-transfer-encoding:\s*\S+/im', $sample)
            || 1 === preg_match('/^mime-version:\s*\d/im', $sample);
    }

    /**
     * Extract human-readable text from a raw MIME message. Returns an empty
     * string when nothing usable could be recovered (the caller then keeps the
     * original body).
     */
    public function extractText(string $raw): string
    {
        $collected = $this->collect($this->parseEntity($raw), 0);

        if ('' !== trim($collected['plain'])) {
            return trim($collected['plain']);
        }

        if ('' !== trim($collected['html'])) {
            return trim((string) strip_tags($collected['html']));
        }

        return '';
    }

    /**
     * Split a MIME entity into its headers and body at the first blank line.
     *
     * @return array{headers: array<string, string>, body: string}
     */
    private function parseEntity(string $raw): array
    {
        $split = preg_split("/\r?\n\r?\n/", $raw, 2);
        if (false === $split || count($split) < 2) {
            return ['headers' => [], 'body' => $raw];
        }

        $headers = $this->parseHeaders($split[0]);
        if ([] === $headers) {
            // The block before the first blank line was not a header block — treat
            // the whole input as a raw (header-less) body.
            return ['headers' => [], 'body' => $raw];
        }

        return ['headers' => $headers, 'body' => $split[1]];
    }

    /**
     * Parse a header block into a lower-cased name => value map, folding RFC 5322
     * continuation lines (leading whitespace) into the preceding header.
     *
     * @return array<string, string>
     */
    private function parseHeaders(string $block): array
    {
        $headers = [];
        $current = null;

        foreach (explode("\n", $block) as $line) {
            $line = rtrim($line, "\r");
            if ('' === $line) {
                continue;
            }

            if (null !== $current && 1 === preg_match('/^[ \t]/', $line)) {
                $headers[$current] .= ' '.trim($line);
                continue;
            }

            if (1 === preg_match('/^([A-Za-z0-9-]+):[ \t]?(.*)$/', $line, $m)) {
                $current = strtolower($m[1]);
                $headers[$current] = $m[2];
            }
        }

        return $headers;
    }

    /**
     * Recursively collect the first text/plain and text/html bodies from an
     * entity, descending into multipart containers.
     *
     * @param array{headers: array<string, string>, body: string} $entity
     *
     * @return array{plain: string, html: string}
     */
    private function collect(array $entity, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return ['plain' => '', 'html' => ''];
        }

        $headers = $entity['headers'];
        $contentType = strtolower($headers['content-type'] ?? 'text/plain');
        $mimeType = trim(explode(';', $contentType)[0]);

        if (str_starts_with($mimeType, 'multipart/')) {
            return $this->collectMultipart($headers, $entity['body'], $depth);
        }

        if ($this->isAttachment($headers)) {
            return ['plain' => '', 'html' => ''];
        }

        $decoded = $this->decodeBody($entity['body'], $headers);

        if ('text/plain' === $mimeType) {
            return ['plain' => $decoded, 'html' => ''];
        }

        if ('text/html' === $mimeType) {
            return ['plain' => '', 'html' => $decoded];
        }

        return ['plain' => '', 'html' => ''];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{plain: string, html: string}
     */
    private function collectMultipart(array $headers, string $body, int $depth): array
    {
        $boundary = $this->boundary($headers['content-type'] ?? '') ?? $this->detectBoundary($body);
        if (null === $boundary) {
            return ['plain' => '', 'html' => ''];
        }

        $plain = '';
        $html = '';

        foreach ($this->splitParts($body, $boundary) as $partRaw) {
            $sub = $this->collect($this->parseEntity($partRaw), $depth + 1);
            if ('' === $plain && '' !== $sub['plain']) {
                $plain = $sub['plain'];
            }
            if ('' === $html && '' !== $sub['html']) {
                $html = $sub['html'];
            }
            if ('' !== $plain && '' !== $html) {
                break;
            }
        }

        return ['plain' => $plain, 'html' => $html];
    }

    /**
     * Split a multipart body into its part sources, dropping the preamble before
     * the first boundary and stopping at the closing `--boundary--` delimiter.
     *
     * @return list<string>
     */
    private function splitParts(string $body, string $boundary): array
    {
        $segments = explode('--'.$boundary, $body);
        array_shift($segments); // preamble before the first boundary

        $parts = [];
        foreach ($segments as $segment) {
            if (str_starts_with($segment, '--')) {
                // Closing delimiter (--boundary--); the rest is the epilogue.
                break;
            }

            // Strip the single CRLF that follows the boundary line.
            $segment = preg_replace("/^\r?\n/", '', $segment, 1) ?? $segment;
            if ('' !== $segment) {
                $parts[] = $segment;
            }
        }

        return $parts;
    }

    /**
     * Read the boundary parameter from a Content-Type header value.
     */
    private function boundary(string $contentType): ?string
    {
        if (1 === preg_match('/boundary\s*=\s*"([^"]+)"/i', $contentType, $m)) {
            return $m[1];
        }
        if (1 === preg_match('/boundary\s*=\s*([^;\s]+)/i', $contentType, $m)) {
            return trim($m[1], "\"'");
        }

        return null;
    }

    /**
     * Fallback: infer the boundary from the body when the Content-Type header did
     * not carry one (e.g. a relay that stripped the parameter but kept the part
     * delimiters).
     */
    private function detectBoundary(string $body): ?string
    {
        if (1 === preg_match('/^--([^\r\n-][^\r\n]*?)\r?\n/m', $body, $m)) {
            return rtrim($m[1]);
        }

        return null;
    }

    /**
     * Decode a leaf part's body per its Content-Transfer-Encoding and convert a
     * declared non-UTF-8 charset to UTF-8.
     *
     * @param array<string, string> $headers
     */
    private function decodeBody(string $body, array $headers): string
    {
        $encoding = strtolower(trim($headers['content-transfer-encoding'] ?? '7bit'));

        $decoded = match ($encoding) {
            'base64' => (string) base64_decode((string) preg_replace('/\s+/', '', $body), false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };

        $charset = $this->charset($headers['content-type'] ?? '');
        if (null !== $charset) {
            $normalized = strtoupper($charset);
            if ('UTF-8' !== $normalized && 'US-ASCII' !== $normalized && 'ASCII' !== $normalized && '' !== $normalized) {
                $converted = @mb_convert_encoding($decoded, 'UTF-8', $normalized);
                if (is_string($converted)) {
                    $decoded = $converted;
                }
            }
        }

        return $decoded;
    }

    private function charset(string $contentType): ?string
    {
        if (1 === preg_match('/charset\s*=\s*"?([^";\s]+)"?/i', $contentType, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @param array<string, string> $headers
     */
    private function isAttachment(array $headers): bool
    {
        $disposition = strtolower(trim($headers['content-disposition'] ?? ''));

        return str_starts_with($disposition, 'attachment');
    }
}
