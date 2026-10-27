<?php

declare(strict_types=1);

namespace App\AI\Stream;

/**
 * Collects the user-visible answer while a stream is forwarded.
 *
 * The facade's chunks are plain text or {@see StreamChunk} arrays. The
 * Messages API emits either those same text deltas as event arrays, or raw
 * SSE bytes that can split mid-line. Both shapes land in {@see text()} so a
 * usage row can estimate tokens when the provider sent none.
 */
final class StreamTextCapture
{
    private string $text = '';

    private string $pending = '';

    public function __construct(private readonly \Closure $inner)
    {
    }

    public static function wrap(callable $inner): self
    {
        return new self($inner instanceof \Closure ? $inner : \Closure::fromCallable($inner));
    }

    public function __invoke(mixed $chunk): void
    {
        $this->absorb($chunk);
        ($this->inner)($chunk);
    }

    public function text(): string
    {
        if ('' === $this->pending) {
            return $this->text;
        }
        if (self::looksLikeSse($this->pending)) {
            $this->text .= self::textFromSseLine(rtrim($this->pending, "\r\n"));
        } else {
            $this->text .= $this->pending;
        }
        $this->pending = '';

        return $this->text;
    }

    private function absorb(mixed $chunk): void
    {
        if (is_string($chunk)) {
            $this->absorbString($chunk);

            return;
        }
        if (!is_array($chunk)) {
            return;
        }
        $data = $chunk['data'] ?? null;
        $delta = is_array($data) ? ($data['delta'] ?? null) : ($chunk['delta'] ?? null);
        if (is_array($delta) && 'text_delta' === ($delta['type'] ?? null) && is_string($delta['text'] ?? null)) {
            $this->text .= $delta['text'];

            return;
        }
        if (is_string($chunk['type'] ?? null) || array_key_exists('content', $chunk)) {
            $this->text .= StreamChunk::visibleText($chunk);
        }
    }

    private function absorbString(string $chunk): void
    {
        $this->pending .= $chunk;
        if (!self::looksLikeSse($this->pending)) {
            $this->text .= $this->pending;
            $this->pending = '';

            return;
        }
        while (false !== ($pos = strpos($this->pending, "\n"))) {
            $line = rtrim(substr($this->pending, 0, $pos), "\r");
            $this->pending = substr($this->pending, $pos + 1);
            $this->text .= self::textFromSseLine($line);
        }
    }

    private static function looksLikeSse(string $buffer): bool
    {
        $trimmed = ltrim($buffer, "\r\n");

        return str_starts_with($trimmed, 'event:')
            || str_starts_with($trimmed, 'data:')
            || str_contains($buffer, "\ndata:")
            || str_contains($buffer, "\nevent:");
    }

    private static function textFromSseLine(string $line): string
    {
        if (!str_starts_with($line, 'data:')) {
            return '';
        }
        $payload = trim(substr($line, 5));
        if ('' === $payload || '[DONE]' === $payload) {
            return '';
        }
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return '';
        }
        $delta = $decoded['delta'] ?? null;
        if (is_array($delta) && 'text_delta' === ($delta['type'] ?? null) && is_string($delta['text'] ?? null)) {
            return $delta['text'];
        }

        return '';
    }
}
