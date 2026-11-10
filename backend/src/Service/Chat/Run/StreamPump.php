<?php

declare(strict_types=1);

namespace App\Service\Chat\Run;

use App\AI\Stream\StreamDecoderInterface;

/**
 * Reads one provider body outside the web request and writes the run stream.
 * The job carries a decoder name and the call payload, never a provider key.
 */
final class StreamPump
{
    private const CREDENTIAL_KEYS = [
        'api_key',
        'apikey',
        'api-key',
        'authorization',
        'proxy-authorization',
        'x-api-key',
        'x-goog-api-key',
    ];

    /**
     * @param array<string, mixed> $call payload without a key or an authorization header
     */
    public function run(string $runId, string $bytes, StreamDecoderInterface $decoder, RunStream $stream, array $call = []): void
    {
        $this->assertNoSecret($call);
        $seq = 0;
        foreach ($decoder->feed($bytes) as $event) {
            $failure = self::failureMessage($event->kind, $event->data);
            if (null !== $failure) {
                $this->fail($runId, $seq, $stream, $failure);
            }
            $text = self::visible($event->kind, $event->data);
            if (null === $text) {
                continue;
            }
            $stream->append($runId, ++$seq, 'frame', self::dataFrame(['text' => $text]));
        }
        $stream->append($runId, ++$seq, 'terminal', "data: [DONE]\n\n");
    }

    /**
     * A provider key is a credential field, a header name, or a header line
     * under `headers`. Message text is not scanned, so a sentence that says
     * "authorization" is still a job.
     *
     * @param array<string, mixed> $call
     */
    private function assertNoSecret(array $call): void
    {
        $this->rejectCredentialKeys($call);
    }

    private function rejectCredentialKeys(mixed $value, bool $headerList = false): void
    {
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower($key), self::CREDENTIAL_KEYS, true)) {
                throw new \InvalidArgumentException('A stream job must not carry a provider key');
            }
            if ($headerList && is_string($item) && self::isCredentialHeaderLine($item)) {
                throw new \InvalidArgumentException('A stream job must not carry a provider key');
            }
            $this->rejectCredentialKeys($item, is_string($key) && 'headers' === strtolower($key));
        }
    }

    private static function isCredentialHeaderLine(string $line): bool
    {
        $colon = strpos($line, ':');
        if (false === $colon) {
            return false;
        }
        $name = strtolower(trim(substr($line, 0, $colon)));

        return in_array($name, self::CREDENTIAL_KEYS, true);
    }

    private static function failureMessage(string $kind, mixed $data): ?string
    {
        if ('error' === $kind) {
            return self::errorMessage($data);
        }
        if ('event' !== $kind || !is_array($data) || !array_key_exists('error', $data)) {
            return null;
        }
        $error = $data['error'];
        if (null === $error || '' === $error || [] === $error) {
            return null;
        }

        return self::errorMessage($data);
    }

    /**
     * The frames already written stay readable. The terminal marks the end,
     * and the exception keeps a failed body from looking like an empty answer.
     */
    private function fail(string $runId, int &$seq, RunStream $stream, string $message): never
    {
        $stream->append($runId, ++$seq, 'frame', self::dataFrame(['error' => $message]));
        $stream->append($runId, ++$seq, 'terminal', "data: [DONE]\n\n");

        throw new \RuntimeException($message);
    }

    private static function visible(string $kind, mixed $data): ?string
    {
        if ('content' === $kind) {
            return self::nonEmpty(self::textParts($data));
        }
        if ('callback' === $kind && is_array($data) && 'content' === ($data['type'] ?? null)) {
            return self::nonEmpty(self::textParts($data['content'] ?? null));
        }
        if ('event' === $kind && is_array($data)) {
            return self::nonEmpty(self::eventText($data));
        }

        return null;
    }

    /**
     * OpenAI sends a string. Mistral Large 4 sends text and thinking parts.
     * Thinking is not the answer.
     */
    private static function textParts(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return '';
        }
        if (isset($content['type']) || isset($content['text']) || isset($content['thinking'])) {
            $content = [$content];
        }

        $text = '';
        foreach ($content as $part) {
            if (is_string($part)) {
                $text .= $part;
                continue;
            }
            if (!is_array($part)) {
                continue;
            }
            $type = is_string($part['type'] ?? null) ? $part['type'] : 'text';
            if ('thinking' === $type || 'reasoning' === $type) {
                continue;
            }
            $piece = $part['text'] ?? '';
            if (is_string($piece)) {
                $text .= $piece;
            }
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function eventText(array $data): string
    {
        $message = is_array($data['message'] ?? null) ? ($data['message']['content'] ?? null) : null;
        if (is_string($message)) {
            return $message;
        }
        if (is_array($message)) {
            return self::textParts($message);
        }

        $candidate = is_array($data['candidates'][0] ?? null) ? $data['candidates'][0] : null;
        $content = is_array($candidate) && is_array($candidate['content'] ?? null) ? $candidate['content'] : null;
        $parts = is_array($content) ? ($content['parts'] ?? null) : null;
        if (!is_array($parts)) {
            return '';
        }

        $text = '';
        foreach ($parts as $part) {
            if (!is_array($part) || !empty($part['thought'])) {
                continue;
            }
            $piece = $part['text'] ?? '';
            if (is_string($piece)) {
                $text .= $piece;
            }
        }

        return $text;
    }

    private static function errorMessage(mixed $data): string
    {
        if (is_string($data) && '' !== $data) {
            return $data;
        }
        if (!is_array($data)) {
            return 'The provider stream failed';
        }
        $error = $data['error'] ?? null;
        if (is_string($error) && '' !== $error) {
            return $error;
        }
        if (!is_array($error)) {
            $error = $data;
        }
        $message = $error['message'] ?? null;

        return is_string($message) && '' !== $message ? $message : 'The provider stream failed';
    }

    private static function nonEmpty(string $text): ?string
    {
        return '' === $text ? null : $text;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function dataFrame(array $payload): string
    {
        $json = json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);

        return 'data: '.($json ?: '{}')."\n\n";
    }
}
